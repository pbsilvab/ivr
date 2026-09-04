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

    private function fakeCallRedirect(): void
    {
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Calls/*' => Http::response(['sid' => 'CAfake'], 200),
        ]);
    }

    private function pendingTask(string $taskSid, string $callSid): TaskRecord
    {
        $call = Call::create([
            'call_sid' => $callSid,
            'from_number' => '+15551234567',
            'status' => 'queued',
            'task_sid' => $taskSid,
        ]);

        return TaskRecord::create([
            'task_sid' => $taskSid,
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);
    }

    public function test_duplicate_incoming_call_reuses_record(): void
    {
        $params = [
            'CallSid' => 'CAidempotencetest001',
            'From' => '+15551234567',
        ];

        $this->twilioPost('/api/voice/incoming', $params)->assertStatus(200);
        $this->twilioPost('/api/voice/incoming', $params)->assertStatus(200);

        $this->assertSame(1, Call::where('call_sid', 'CAidempotencetest001')->count());
    }

    public function test_duplicate_task_created_event_records_one_task(): void
    {
        $call = Call::create([
            'call_sid' => 'CAcreatedduptest001',
            'from_number' => '+15551234567',
            'status' => 'queued',
        ]);

        $params = [
            'EventType' => 'task.created',
            'TaskSid' => 'WTcreatedduptest001',
            'TaskAttributes' => json_encode(['callSid' => 'CAcreatedduptest001']),
        ];

        $this->twilioPost('/api/taskrouter/events', $params)->assertStatus(200);
        $this->twilioPost('/api/taskrouter/events', $params)->assertStatus(200);

        $this->assertSame(1, TaskRecord::where('call_id', $call->id)->count());
    }

    public function test_duplicate_voicemail_record_does_not_send_duplicate_sms(): void
    {
        Agent::create([
            'name' => 'voicemail_dup_agent',
            'phone_number' => '+15555555555',
        ]);

        Call::create([
            'call_sid' => 'CAvoicemailduptest001',
            'from_number' => '+15551234567',
            'status' => 'initiated',
        ]);

        $smsCallCount = 0;
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => function () use (&$smsCallCount) {
                $smsCallCount++;

                return Http::response(['sid' => 'SMvoicemailduptest00'.$smsCallCount], 201);
            },
        ]);

        $params = [
            'CallSid' => 'CAvoicemailduptest001',
            'RecordingSid' => 'REvoicemailduptest001',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REvoicemailduptest001',
        ];

        $this->twilioPost('/api/voice/voicemail-record', $params)->assertStatus(200);
        $this->twilioPost('/api/voice/voicemail-record', $params)->assertStatus(200);

        $this->assertSame(1, $smsCallCount);
    }

    public function test_duplicate_assignment_callback_instructs_only_once(): void
    {
        Agent::create([
            'name' => 'assignment_dup_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKassignmentduptest001',
        ]);

        $this->pendingTask('WTassignmentduptest001', 'CAassignmentduptest001');

        $params = [
            'TaskSid' => 'WTassignmentduptest001',
            'WorkerSid' => 'WKassignmentduptest001',
            'ReservationSid' => 'WRassignmentduptest001',
        ];

        $first = $this->twilioPost('/api/taskrouter/assignment', $params);
        $first->assertStatus(200);
        $this->assertSame('dequeue', $first->json('instruction'));

        // The Task is no longer pending, so a retry must not issue a second dequeue.
        $second = $this->twilioPost('/api/taskrouter/assignment', $params);
        $second->assertStatus(200);
        $this->assertSame('', $second->getContent());

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTassignmentduptest001',
            'status' => 'accepted',
            'reservation_sid' => 'WRassignmentduptest001',
        ]);
    }

    public function test_duplicate_task_canceled_event_redirects_the_call_once(): void
    {
        $this->fakeCallRedirect();
        $this->pendingTask('WTcanceledduptest001', 'CAcanceledduptest001');

        $params = ['EventType' => 'task.canceled', 'TaskSid' => 'WTcanceledduptest001'];

        for ($i = 0; $i < 3; $i++) {
            $this->twilioPost('/api/taskrouter/events', $params)->assertStatus(200);
        }

        Http::assertSentCount(1);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTcanceledduptest001',
            'status' => 'timeout',
        ]);
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAcanceledduptest001',
            'status' => 'agent_unavailable',
            'outcome' => 'no_agent',
        ]);
    }

    public function test_task_canceled_then_delayed_assignment_ignores_the_assignment(): void
    {
        $this->fakeCallRedirect();

        Agent::create([
            'name' => 'outoforder_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKoutofordertest001',
        ]);

        $this->pendingTask('WToutofordertest001', 'CAoutofordertest001');

        $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'task.canceled',
            'TaskSid' => 'WToutofordertest001',
        ])->assertStatus(200);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WToutofordertest001',
            'status' => 'timeout',
        ]);

        $late = $this->twilioPost('/api/taskrouter/assignment', [
            'TaskSid' => 'WToutofordertest001',
            'WorkerSid' => 'WKoutofordertest001',
            'ReservationSid' => 'WRoutofordertest001',
        ]);

        $late->assertStatus(200);
        $this->assertSame('', $late->getContent());

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WToutofordertest001',
            'status' => 'timeout',
        ]);
    }

    public function test_assignment_then_delayed_task_canceled_keeps_the_call_connected(): void
    {
        $this->fakeCallRedirect();

        $agent = Agent::create([
            'name' => 'acceptedorder_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKacceptedordertest001',
        ]);

        $this->pendingTask('WTacceptedordertest001', 'CAacceptedordertest001');

        $this->twilioPost('/api/taskrouter/assignment', [
            'TaskSid' => 'WTacceptedordertest001',
            'WorkerSid' => 'WKacceptedordertest001',
            'ReservationSid' => 'WRacceptedordertest001',
        ])->assertStatus(200);

        $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'task.canceled',
            'TaskSid' => 'WTacceptedordertest001',
        ])->assertStatus(200);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTacceptedordertest001',
            'status' => 'accepted',
            'reservation_sid' => 'WRacceptedordertest001',
        ]);
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAacceptedordertest001',
            'status' => 'accepted',
            'agent_id' => $agent->id,
        ]);

        // The caller is talking to the agent — they must not be redirected to voicemail.
        Http::assertNothingSent();
    }
}
