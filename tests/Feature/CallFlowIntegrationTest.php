<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Call;
use App\Models\TaskRecord;
use App\Models\Voicemail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CallFlowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_call_flow_incoming_to_agent_dequeue(): void
    {
        $agent = Agent::create([
            'name' => 'integration_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKintegrationtest001',
        ]);

        // Step 1: Incoming call
        $response1 = $this->twilioPost('/api/voice/incoming', [
            'CallSid' => 'CAintegrationflow001',
            'From' => '+15559999999',
        ]);
        $response1->assertStatus(200);
        $this->assertStringContainsString('<Gather', $response1->getContent());
        $this->assertStringContainsString('/api/voice/gather-digits', $response1->getContent());

        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAintegrationflow001',
            'from_number' => '+15559999999',
            'status' => 'initiated',
        ]);

        // Step 2: caller presses 1 and lands in the Workflow's queue
        $response2 = $this->twilioPost('/api/voice/gather-digits', [
            'CallSid' => 'CAintegrationflow001',
            'Digits' => '1',
        ]);
        $response2->assertStatus(200);
        $this->assertStringContainsString('<Enqueue', $response2->getContent());

        // Step 3: TaskRouter reports the Task it built from <Enqueue> — this is where we learn its SID
        $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'task.created',
            'TaskSid' => 'WTintegrationflow001',
            'TaskAttributes' => json_encode(['callSid' => 'CAintegrationflow001', 'from' => '+15559999999']),
        ])->assertStatus(200);

        $call = Call::where('call_sid', 'CAintegrationflow001')->first();
        $this->assertSame('WTintegrationflow001', $call->task_sid);
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTintegrationflow001',
            'call_id' => $call->id,
            'status' => 'pending',
        ]);

        // Step 4: a Worker is reserved and we instruct TaskRouter to bridge the queued caller
        $response4 = $this->twilioPost('/api/taskrouter/assignment', [
            'TaskSid' => 'WTintegrationflow001',
            'WorkerSid' => 'WKintegrationtest001',
            'ReservationSid' => 'WRintegrationflow001',
        ]);
        $response4->assertStatus(200);
        $this->assertSame('dequeue', $response4->json('instruction'));
        $this->assertSame($agent->phone_number, $response4->json('to'));

        $call->refresh();
        $this->assertEquals('accepted', $call->status);
        $this->assertEquals($agent->id, $call->agent_id);

        $taskRecord = TaskRecord::where('task_sid', 'WTintegrationflow001')->first();
        $this->assertEquals('accepted', $taskRecord->status);
        $this->assertEquals('WRintegrationflow001', $taskRecord->reservation_sid);
    }

    public function test_voicemail_fallback_when_no_agent_accepts(): void
    {
        $agent = Agent::create([
            'name' => 'voicemail_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKvoicemailtest001',
        ]);

        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Calls/*' => Http::response(['sid' => 'CAfake'], 200),
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SMvoicemailflow001'], 201),
        ]);

        // Step 1: Incoming call
        $this->twilioPost('/api/voice/incoming', [
            'CallSid' => 'CAvoicemailflow001',
            'From' => '+15559999999',
        ])->assertStatus(200);

        // Step 2: caller presses 1 and is enqueued
        $this->twilioPost('/api/voice/gather-digits', [
            'CallSid' => 'CAvoicemailflow001',
            'Digits' => '1',
        ])->assertStatus(200);

        $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'task.created',
            'TaskSid' => 'WTvoicemailflow001',
            'TaskAttributes' => json_encode(['callSid' => 'CAvoicemailflow001']),
        ])->assertStatus(200);

        $call = Call::where('call_sid', 'CAvoicemailflow001')->first();

        // Step 3: nobody accepts, so the Workflow timeout cancels the Task
        $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'task.canceled',
            'TaskSid' => 'WTvoicemailflow001',
        ])->assertStatus(200);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTvoicemailflow001',
            'status' => 'timeout',
        ]);
        $call->refresh();
        $this->assertEquals('agent_unavailable', $call->status);
        $this->assertEquals('no_agent', $call->outcome);

        // The caller is still in <Enqueue>; redirecting the live call is what moves them on.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/Calls/CAvoicemailflow001.json')
            && $request['Url'] === url('/api/voice/no-agent-available'));

        // Step 4: the redirected call lands on the voicemail prompt
        $response4 = $this->twilioPost('/api/voice/no-agent-available', ['CallSid' => 'CAvoicemailflow001']);
        $response4->assertStatus(200);
        $this->assertStringContainsString('<Record', $response4->getContent());

        // Step 5: Voicemail recorded
        $response5 = $this->twilioPost('/api/voice/voicemail-record', [
            'CallSid' => 'CAvoicemailflow001',
            'RecordingSid' => 'REvoicemailflow001',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REvoicemailflow001',
        ]);
        $response5->assertStatus(200);
        $this->assertStringContainsString('Thank you', $response5->getContent());

        // Verify voicemail recorded and agents notified
        $this->assertDatabaseHas('voicemails', [
            'call_id' => $call->id,
            'recording_sid' => 'REvoicemailflow001',
            'recording_url' => 'https://api.twilio.com/recordings/REvoicemailflow001',
        ]);

        $call->refresh();
        $this->assertEquals('voicemail_recorded', $call->status);
    }

    public function test_caller_chooses_voicemail_directly_from_ivr(): void
    {
        $agent = Agent::create([
            'name' => 'directvm_agent',
            'phone_number' => '+15551234567',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';

        // Step 1: Incoming call
        $incoming_url = url('/api/voice/incoming');
        $incoming_params = [
            'CallSid' => 'CAdirectvmflow001',
            'From' => '+15559999999',
        ];
        $incoming_sig = $this->computeSignature($incoming_url, $incoming_params, $authToken);

        $response1 = $this->post('/api/voice/incoming', $incoming_params, [
            'X-Twilio-Signature' => $incoming_sig,
        ]);
        $response1->assertStatus(200);

        // Step 2: User presses 2 for voicemail
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
                'sid' => 'SMdirectvmflow001',
            ], 201),
        ]);

        $gather_url = url('/api/voice/gather-digits');
        $gather_params = [
            'CallSid' => 'CAdirectvmflow001',
            'Digits' => '2',
        ];
        $gather_sig = $this->computeSignature($gather_url, $gather_params, $authToken);

        $response2 = $this->post('/api/voice/gather-digits', $gather_params, [
            'X-Twilio-Signature' => $gather_sig,
        ]);
        $response2->assertStatus(200);
        $this->assertStringContainsString('<Record', $response2->getContent());

        // Step 3: Voicemail recorded
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
                'sid' => 'SMdirectvmflow001',
            ], 201),
        ]);

        $voicemail_url = url('/api/voice/voicemail-record');
        $voicemail_params = [
            'CallSid' => 'CAdirectvmflow001',
            'RecordingSid' => 'REdirectvmflow001',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REdirectvmflow001',
        ];
        $voicemail_sig = $this->computeSignature($voicemail_url, $voicemail_params, $authToken);

        $response3 = $this->post('/api/voice/voicemail-record', $voicemail_params, [
            'X-Twilio-Signature' => $voicemail_sig,
        ]);
        $response3->assertStatus(200);

        // Verify voicemail recorded
        $call = Call::where('call_sid', 'CAdirectvmflow001')->first();
        $this->assertEquals('voicemail_recorded', $call->status);
    }

    public function test_concurrent_calls_with_single_agent(): void
    {
        $agent = Agent::create([
            'name' => 'concurrent_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKconcurrenttest001',
        ]);

        // Create two incoming calls
        for ($i = 1; $i <= 2; $i++) {
            $this->twilioPost('/api/voice/incoming', [
                'CallSid' => "CAconcurrentflow00{$i}",
                'From' => '+1555999999'.$i,
            ])->assertStatus(200);
        }

        $this->assertEquals(2, Call::count());

        // Caller on the first call presses 1 and is enqueued
        $this->twilioPost('/api/voice/gather-digits', [
            'CallSid' => 'CAconcurrentflow001',
            'Digits' => '1',
        ])->assertStatus(200);

        $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'task.created',
            'TaskSid' => 'WTconcurrentflow001',
            'TaskAttributes' => json_encode(['callSid' => 'CAconcurrentflow001']),
        ])->assertStatus(200);

        // The only agent is reserved for the first call
        $this->twilioPost('/api/taskrouter/assignment', [
            'TaskSid' => 'WTconcurrentflow001',
            'WorkerSid' => 'WKconcurrenttest001',
            'ReservationSid' => 'WRconcurrentflow001',
        ])->assertStatus(200);

        // Verify first call is accepted
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAconcurrentflow001',
            'status' => 'accepted',
            'agent_id' => $agent->id,
        ]);

        // Second call still pending
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAconcurrentflow002',
            'status' => 'initiated',
        ]);

        // Verify TaskRecord for first call is accepted
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTconcurrentflow001',
            'status' => 'accepted',
        ]);
    }

    public function test_agent_becomes_unavailable_during_call_flow(): void
    {
        $agent = Agent::create([
            'name' => 'unavailable_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKunavailabletest001',
            'status' => 'available',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';

        // Step 1: Incoming call
        $incoming_url = url('/api/voice/incoming');
        $incoming_params = [
            'CallSid' => 'CAunavailableflow001',
            'From' => '+15559999999',
        ];
        $incoming_sig = $this->computeSignature($incoming_url, $incoming_params, $authToken);

        $this->post('/api/voice/incoming', $incoming_params, [
            'X-Twilio-Signature' => $incoming_sig,
        ])->assertStatus(200);

        // Step 2: Agent becomes unavailable
        Http::fake([
            'https://taskrouter.twilio.com/*' => function ($request) {
                // Handle Worker update
                if ($request->method() === 'POST' && str_contains($request->url(), '/Workers/WKunavailabletest001')) {
                    return Http::response([
                        'sid' => 'WKunavailabletest001',
                        'activitySid' => config('services.twilio.activity_unavailable'),
                    ], 200);
                }
                // Handle Task creation
                if ($request->method() === 'POST' && str_contains($request->url(), '/Tasks')) {
                    return Http::response([
                        'sid' => 'WTunavailableflow001',
                        'workflowSid' => config('services.twilio.workflow_sid'),
                        'attributes' => '{"callSid":"CAunavailableflow001","from":"+15559999999"}',
                        'status' => 'pending',
                    ], 201);
                }

                return Http::response([], 200);
            },
        ]);

        $toggle_url = url('/api/agents/'.$agent->id.'/availability/set');
        $toggle_params = [
            'status' => 'unavailable',
        ];

        // Note: toggle endpoint may use form data instead of JSON
        $this->postJson($toggle_url, $toggle_params)
            ->assertStatus(200);

        // Verify agent is unavailable
        $agent->refresh();
        $this->assertEquals('unavailable', $agent->status);
    }

    public function test_invalid_digit_repeats_ivr_prompt(): void
    {
        $authToken = config('services.twilio.token') ?? 'test_token';

        // Incoming call
        $incoming_url = url('/api/voice/incoming');
        $incoming_params = [
            'CallSid' => 'CAinvaliddigitflow001',
            'From' => '+15559999999',
        ];
        $incoming_sig = $this->computeSignature($incoming_url, $incoming_params, $authToken);

        $this->post('/api/voice/incoming', $incoming_params, [
            'X-Twilio-Signature' => $incoming_sig,
        ])->assertStatus(200);

        // User presses invalid digit (5)
        $gather_url = url('/api/voice/gather-digits');
        $gather_params = [
            'CallSid' => 'CAinvaliddigitflow001',
            'Digits' => '5',
        ];
        $gather_sig = $this->computeSignature($gather_url, $gather_params, $authToken);

        $response = $this->post('/api/voice/gather-digits', $gather_params, [
            'X-Twilio-Signature' => $gather_sig,
        ]);
        $response->assertStatus(200);
        // Invalid input should redirect back to incoming endpoint
        $this->assertStringContainsString('<Redirect', $response->getContent());
        $this->assertStringContainsString('/api/voice/incoming', $response->getContent());
    }
}
