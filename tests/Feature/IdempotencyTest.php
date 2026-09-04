<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Call;
use App\Models\TaskRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function computeSignature(string $url, array $params, string $authToken): string
    {
        $data = $url;
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = implode('', $value);
            }
            $data .= $key . $value;
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }

    public function test_duplicate_incoming_call_reuses_record(): void
    {
        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/incoming');
        $params = [
            'CallSid' => 'CAidempotencetest001',
            'From' => '+15551234567',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        // First call
        $response1 = $this->post('/api/voice/incoming', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response1->assertStatus(200);

        // Second identical call
        $response2 = $this->post('/api/voice/incoming', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response2->assertStatus(200);

        // Should only have one Call record
        $this->assertEquals(1, Call::where('call_sid', 'CAidempotencetest001')->count());
    }

    public function test_duplicate_gather_digits_press_1_does_not_create_duplicate_task(): void
    {
        $call = Call::create([
            'call_sid' => 'CAgatherduptest001',
            'from_number' => '+15551234567',
            'status' => 'initiated',
        ]);

        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response([
                'sid' => 'WTgatherduptest001',
                'workflowSid' => config('services.twilio.workflow_sid'),
                'attributes' => '{"callSid":"CAgatherduptest001","from":"+15551234567"}',
                'status' => 'pending',
            ], 201),
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/gather-digits');
        $params = [
            'CallSid' => 'CAgatherduptest001',
            'Digits' => '1',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        // First gather-digits
        $response1 = $this->post('/api/voice/gather-digits', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response1->assertStatus(200);

        // Second identical gather-digits
        $response2 = $this->post('/api/voice/gather-digits', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response2->assertStatus(200);

        // Should only have one TaskRecord
        $this->assertEquals(1, TaskRecord::where('call_id', $call->id)->count());

        // Both responses should contain Enqueue
        $this->assertStringContainsString('<Enqueue', $response1->getContent());
        $this->assertStringContainsString('<Enqueue', $response2->getContent());
    }

    public function test_duplicate_voicemail_record_does_not_send_duplicate_sms(): void
    {
        $agent = Agent::create([
            'name' => 'voicemail_dup_agent',
            'phone_number' => '+15555555555',
        ]);

        $call = Call::create([
            'call_sid' => 'CAvoicemailduptest001',
            'from_number' => '+15551234567',
            'status' => 'initiated',
        ]);

        $smsCallCount = 0;
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => function () use (&$smsCallCount) {
                $smsCallCount++;
                return Http::response([
                    'sid' => 'SMvoicemailduptest00' . $smsCallCount,
                    'from' => config('services.twilio.number'),
                    'to' => '+15555555555',
                    'body' => 'New voicemail',
                ], 201);
            },
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/voicemail-record');
        $params = [
            'CallSid' => 'CAvoicemailduptest001',
            'RecordingSid' => 'REvoicemailduptest001',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REvoicemailduptest001',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        // First voicemail-record
        $response1 = $this->post('/api/voice/voicemail-record', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response1->assertStatus(200);

        // Second identical voicemail-record
        $response2 = $this->post('/api/voice/voicemail-record', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response2->assertStatus(200);

        // Only one SMS should have been sent (on first call, sms_sid prevents second)
        $this->assertEquals(1, $smsCallCount);
    }

    public function test_duplicate_assignment_callback_accepted_does_not_change_status(): void
    {
        $agent = Agent::create([
            'name' => 'assignment_dup_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKassignmentduptest001',
        ]);

        $call = Call::create([
            'call_sid' => 'CAassignmentduptest001',
            'from_number' => '+15559999999',
            'status' => 'initiated',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTassignmentduptest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/taskrouter/assignment');
        $params = [
            'TaskSid' => 'WTassignmentduptest001',
            'WorkerSid' => 'WKassignmentduptest001',
            'AssignmentStatus' => 'accepted',
            'ReservationSid' => 'WRassignmentduptest001',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        // First assignment callback
        $response1 = $this->post('/api/taskrouter/assignment', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response1->assertStatus(200);

        // Verify status was updated
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTassignmentduptest001',
            'status' => 'accepted',
        ]);

        // Second identical assignment callback
        $response2 = $this->post('/api/taskrouter/assignment', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response2->assertStatus(200);

        // Status should still be 'accepted', not changed
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTassignmentduptest001',
            'status' => 'accepted',
        ]);

        // Both responses should contain Dial
        $this->assertStringContainsString('<Dial', $response1->getContent());
        $this->assertStringContainsString('<Dial', $response2->getContent());
    }

    public function test_duplicate_timeout_event_does_not_update_twice(): void
    {
        $call = Call::create([
            'call_sid' => 'CAtimeoutduptest001',
            'from_number' => '+15551234567',
            'status' => 'initiated',
            'task_sid' => 'WTtimeoutduptest001',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTtimeoutduptest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/taskrouter/events');
        $params = [
            'TaskSid' => 'WTtimeoutduptest001',
            'TaskStatus' => 'wrapup',
            'EventType' => 'task.completed',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        // First timeout event
        $response1 = $this->post('/api/taskrouter/events', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response1->assertStatus(200);

        // Verify status was updated
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTtimeoutduptest001',
            'status' => 'timeout',
        ]);

        // Second identical timeout event
        $response2 = $this->post('/api/taskrouter/events', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response2->assertStatus(200);

        // Status should still be 'timeout', only one update occurred
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTtimeoutduptest001',
            'status' => 'timeout',
        ]);

        // Call should be marked as agent_unavailable
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAtimeoutduptest001',
            'status' => 'agent_unavailable',
            'outcome' => 'no_agent',
        ]);
    }

    public function test_duplicate_assignment_rejected_idempotent(): void
    {
        $call = Call::create([
            'call_sid' => 'CArejectedduptest001',
            'from_number' => '+15551234567',
            'status' => 'initiated',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTrejectedduptest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/taskrouter/assignment');
        $params = [
            'TaskSid' => 'WTrejectedduptest001',
            'WorkerSid' => 'WKunknown',
            'AssignmentStatus' => 'rejected',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        // First rejection
        $response1 = $this->post('/api/taskrouter/assignment', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response1->assertStatus(200);

        // Verify status
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTrejectedduptest001',
            'status' => 'rejected',
        ]);

        // Second identical rejection
        $response2 = $this->post('/api/taskrouter/assignment', $params, [
            'X-Twilio-Signature' => $signature,
        ]);
        $response2->assertStatus(200);

        // Still rejected, no changes
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTrejectedduptest001',
            'status' => 'rejected',
        ]);
    }
}

