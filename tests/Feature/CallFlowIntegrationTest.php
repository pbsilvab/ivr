<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Call;
use App\Models\TaskRecord;
use App\Models\Voicemail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CallFlowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_call_flow_incoming_to_agent_dial(): void
    {
        $agent = Agent::create([
            'name' => 'integration_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKintegrationtest001',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';

        // Step 1: Incoming call
        $incoming_url = url('/api/voice/incoming');
        $incoming_params = [
            'CallSid' => 'CAintegrationflow001',
            'From' => '+15559999999',
        ];
        $incoming_sig = $this->computeSignature($incoming_url, $incoming_params, $authToken);

        $response1 = $this->post('/api/voice/incoming', $incoming_params, [
            'X-Twilio-Signature' => $incoming_sig,
        ]);
        $response1->assertStatus(200);
        $this->assertStringContainsString('<Gather', $response1->getContent());
        $this->assertStringContainsString('action=', $response1->getContent());
        $this->assertStringContainsString('/api/voice/gather-digits', $response1->getContent());

        // Verify Call record created
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAintegrationflow001',
            'from_number' => '+15559999999',
            'status' => 'initiated',
        ]);

        // Step 2: User presses 1 for agent
        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response([
                'sid' => 'WTintegrationflow001',
                'workflowSid' => config('services.twilio.workflow_sid'),
                'attributes' => '{"callSid":"CAintegrationflow001","from":"+15559999999"}',
                'status' => 'pending',
            ], 201),
        ]);

        $gather_url = url('/api/voice/gather-digits');
        $gather_params = [
            'CallSid' => 'CAintegrationflow001',
            'Digits' => '1',
        ];
        $gather_sig = $this->computeSignature($gather_url, $gather_params, $authToken);

        $response2 = $this->post('/api/voice/gather-digits', $gather_params, [
            'X-Twilio-Signature' => $gather_sig,
        ]);
        $response2->assertStatus(200);
        $this->assertStringContainsString('<Enqueue', $response2->getContent());

        // Verify Task created
        $call = Call::where('call_sid', 'CAintegrationflow001')->first();
        $this->assertNotNull($call->task_sid);
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTintegrationflow001',
            'call_id' => $call->id,
            'status' => 'pending',
        ]);

        // Step 3: TaskRouter assignment - agent accepts
        $assign_url = url('/api/taskrouter/assignment');
        $assign_params = [
            'TaskSid' => 'WTintegrationflow001',
            'WorkerSid' => 'WKintegrationtest001',
            'AssignmentStatus' => 'accepted',
            'ReservationSid' => 'WRintegrationflow001',
        ];
        $assign_sig = $this->computeSignature($assign_url, $assign_params, $authToken);

        $response3 = $this->post('/api/taskrouter/assignment', $assign_params, [
            'X-Twilio-Signature' => $assign_sig,
        ]);
        $response3->assertStatus(200);
        $this->assertStringContainsString('<Dial', $response3->getContent());
        $this->assertStringContainsString($agent->phone_number, $response3->getContent());

        // Verify Call and Task records updated
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

        $authToken = config('services.twilio.token') ?? 'test_token';

        // Step 1: Incoming call
        $incoming_url = url('/api/voice/incoming');
        $incoming_params = [
            'CallSid' => 'CAvoicemailflow001',
            'From' => '+15559999999',
        ];
        $incoming_sig = $this->computeSignature($incoming_url, $incoming_params, $authToken);

        $response1 = $this->post('/api/voice/incoming', $incoming_params, [
            'X-Twilio-Signature' => $incoming_sig,
        ]);
        $response1->assertStatus(200);

        // Step 2: User presses 1 for agent
        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response([
                'sid' => 'WTvoicemailflow001',
                'workflowSid' => config('services.twilio.workflow_sid'),
                'attributes' => '{"callSid":"CAvoicemailflow001","from":"+15559999999"}',
                'status' => 'pending',
            ], 201),
        ]);

        $gather_url = url('/api/voice/gather-digits');
        $gather_params = [
            'CallSid' => 'CAvoicemailflow001',
            'Digits' => '1',
        ];
        $gather_sig = $this->computeSignature($gather_url, $gather_params, $authToken);

        $response2 = $this->post('/api/voice/gather-digits', $gather_params, [
            'X-Twilio-Signature' => $gather_sig,
        ]);
        $response2->assertStatus(200);

        $call = Call::where('call_sid', 'CAvoicemailflow001')->first();

        // Step 3: TaskRouter timeout event (no agent accepted)
        $timeout_url = url('/api/taskrouter/events');
        $timeout_params = [
            'TaskSid' => 'WTvoicemailflow001',
            'TaskStatus' => 'completed',
            'EventType' => 'task.completed',
        ];
        $timeout_sig = $this->computeSignature($timeout_url, $timeout_params, $authToken);

        $response3 = $this->post('/api/taskrouter/events', $timeout_params, [
            'X-Twilio-Signature' => $timeout_sig,
        ]);
        $response3->assertStatus(200);

        // Verify task marked timeout and call marked agent_unavailable
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTvoicemailflow001',
            'status' => 'timeout',
        ]);
        $call->refresh();
        $this->assertEquals('agent_unavailable', $call->status);
        $this->assertEquals('no_agent', $call->outcome);

        // Step 4: Fallback to voicemail - Agent calls no_agent_available endpoint
        $noagent_url = url('/api/voice/no-agent-available');
        $noagent_params = [
            'CallSid' => 'CAvoicemailflow001',
        ];
        $noagent_sig = $this->computeSignature($noagent_url, $noagent_params, $authToken);

        $response4 = $this->post('/api/voice/no-agent-available', $noagent_params, [
            'X-Twilio-Signature' => $noagent_sig,
        ]);
        $response4->assertStatus(200);
        $this->assertStringContainsString('<Record', $response4->getContent());

        // Step 5: Voicemail recorded
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
                'sid' => 'SMvoicemailflow001',
            ], 201),
        ]);

        $voicemail_url = url('/api/voice/voicemail-record');
        $voicemail_params = [
            'CallSid' => 'CAvoicemailflow001',
            'RecordingSid' => 'REvoicemailflow001',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REvoicemailflow001',
        ];
        $voicemail_sig = $this->computeSignature($voicemail_url, $voicemail_params, $authToken);

        $response5 = $this->post('/api/voice/voicemail-record', $voicemail_params, [
            'X-Twilio-Signature' => $voicemail_sig,
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

        $authToken = config('services.twilio.token') ?? 'test_token';

        // Create two incoming calls
        for ($i = 1; $i <= 2; $i++) {
            $incoming_url = url('/api/voice/incoming');
            $incoming_params = [
                'CallSid' => "CAconcurrentflow00{$i}",
                'From' => '+1555999999'.$i,
            ];
            $incoming_sig = $this->computeSignature($incoming_url, $incoming_params, $authToken);

            $this->post('/api/voice/incoming', $incoming_params, [
                'X-Twilio-Signature' => $incoming_sig,
            ])->assertStatus(200);
        }

        // Verify both Call records created
        $this->assertEquals(2, Call::count());

        // User on first call presses 1 for agent
        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response([
                'sid' => 'WTconcurrentflow001',
                'workflowSid' => config('services.twilio.workflow_sid'),
                'attributes' => '{"callSid":"CAconcurrentflow001","from":"+15559999991"}',
                'status' => 'pending',
            ], 201),
        ]);

        $gather_url = url('/api/voice/gather-digits');
        $gather_params = [
            'CallSid' => 'CAconcurrentflow001',
            'Digits' => '1',
        ];
        $gather_sig = $this->computeSignature($gather_url, $gather_params, $authToken);

        $this->post('/api/voice/gather-digits', $gather_params, [
            'X-Twilio-Signature' => $gather_sig,
        ])->assertStatus(200);

        // Agent accepts first call
        $assign_url = url('/api/taskrouter/assignment');
        $assign_params = [
            'TaskSid' => 'WTconcurrentflow001',
            'WorkerSid' => 'WKconcurrenttest001',
            'AssignmentStatus' => 'accepted',
            'ReservationSid' => 'WRconcurrentflow001',
        ];
        $assign_sig = $this->computeSignature($assign_url, $assign_params, $authToken);

        $this->post('/api/taskrouter/assignment', $assign_params, [
            'X-Twilio-Signature' => $assign_sig,
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
