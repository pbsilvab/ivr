<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Call;
use App\Models\TaskRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskRouterControllerTest extends TestCase
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

    public function test_assignment_accepted_dials_agent(): void
    {
        // Create agent, call, and task
        $agent = Agent::create([
            'name' => 'test_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKtest123456789aabbccdd',
        ]);

        $call = Call::create([
            'call_sid' => 'CAssignTest001',
            'from_number' => '+15559999999',
            'status' => 'initiated',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTassigntest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/taskrouter/assignment');
        $params = [
            'TaskSid' => 'WTassigntest001',
            'WorkerSid' => 'WKtest123456789aabbccdd',
            'AssignmentStatus' => 'accepted',
            'ReservationSid' => 'WRreservetest001',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/taskrouter/assignment', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString($agent->phone_number, $content);

        // Verify task and call status updated
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTassigntest001',
            'status' => 'accepted',
            'reservation_sid' => 'WRreservetest001',
        ]);
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAssignTest001',
            'status' => 'accepted',
            'agent_id' => $agent->id,
        ]);
    }

    public function test_assignment_rejected_awaits_reassignment(): void
    {
        $call = Call::create([
            'call_sid' => 'CRejectTest001',
            'from_number' => '+15551111111',
            'status' => 'initiated',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTrejecttest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/taskrouter/assignment');
        $params = [
            'TaskSid' => 'WTrejecttest001',
            'WorkerSid' => 'WKunknown123456789',
            'AssignmentStatus' => 'rejected',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/taskrouter/assignment', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('unavailable', $content);

        // Verify task status is rejected
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTrejecttest001',
            'status' => 'rejected',
        ]);
    }

    public function test_assignment_timeout_awaits_reassignment(): void
    {
        $call = Call::create([
            'call_sid' => 'CTimeoutTest001',
            'from_number' => '+15552222222',
            'status' => 'initiated',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTtimeouttest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/taskrouter/assignment');
        $params = [
            'TaskSid' => 'WTtimeouttest001',
            'WorkerSid' => 'WKworker123',
            'AssignmentStatus' => 'timeout',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/taskrouter/assignment', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('unavailable', $content);
    }

    public function test_assignment_accepted_unknown_worker_hangs_up(): void
    {
        $call = Call::create([
            'call_sid' => 'CUnknownWorkerTest001',
            'from_number' => '+15553333333',
            'status' => 'initiated',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTunknowntest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/taskrouter/assignment');
        $params = [
            'TaskSid' => 'WTunknowntest001',
            'WorkerSid' => 'WKunknownworker999',
            'AssignmentStatus' => 'accepted',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/taskrouter/assignment', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Unable to connect', $content);
        $this->assertStringContainsString('Hangup', $content);
    }
}

