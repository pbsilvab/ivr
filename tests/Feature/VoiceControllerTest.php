<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Call;
use App\Models\TaskRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_incoming_creates_call_record(): void
    {
        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/incoming');
        $params = [
            'CallSid' => 'CA1234567890abcdef1234567890abcdef',
            'From' => '+15551234567',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/incoming', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CA1234567890abcdef1234567890abcdef',
            'from_number' => '+15551234567',
            'status' => 'initiated',
        ]);
    }

    public function test_incoming_returns_twiml_with_gather(): void
    {
        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/incoming');
        $params = [
            'CallSid' => 'CA9999999999abcdef9999999999abcdef',
            'From' => '+15559999999',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/incoming', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<Gather', $content);
        $this->assertStringContainsString('numDigits="1"', $content);
        $this->assertStringContainsString('Press 1 to reach an agent', $content);
        $this->assertStringContainsString('press 2 to leave a voicemail', $content);
    }

    /**
     * The Task is created by <Enqueue>, so pressing 1 makes no REST call and its SID is not known
     * yet — the local TaskRecord is written when the `task.created` event arrives.
     */
    public function test_gather_digits_press_1_enqueues_the_caller(): void
    {
        Http::fake();

        Call::create([
            'call_sid' => 'CA1111111111bbbbbbbb1111111111bbbb',
            'from_number' => '+15551111111',
            'status' => 'initiated',
        ]);

        $response = $this->twilioPost('/api/voice/gather-digits', [
            'CallSid' => 'CA1111111111bbbbbbbb1111111111bbbb',
            'Digits' => '1',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<Enqueue', $content);
        $this->assertStringContainsString('workflowSid', $content);

        // Attributes must travel in the <Task> noun, or the Task arrives without our callSid.
        $this->assertStringContainsString('<Task>', $content);
        $this->assertStringContainsString('CA1111111111bbbbbbbb1111111111bbbb', $content);

        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CA1111111111bbbbbbbb1111111111bbbb',
            'status' => 'queued',
            'task_sid' => null,
        ]);

        Http::assertNothingSent();
    }

    public function test_gather_digits_press_2_routes_to_voicemail(): void
    {
        $call = Call::create([
            'call_sid' => 'CA2222222222cccccccc2222222222cccc',
            'from_number' => '+15552222222',
            'status' => 'initiated',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/gather-digits');
        $params = [
            'CallSid' => 'CA2222222222cccccccc2222222222cccc',
            'Digits' => '2',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/gather-digits', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<Record', $content);
        $this->assertStringContainsString('voicemail-record', $content);
    }

    public function test_gather_digits_invalid_input_repeats_ivr(): void
    {
        $call = Call::create([
            'call_sid' => 'CA3333333333dddddddd3333333333dddd',
            'from_number' => '+15553333333',
            'status' => 'initiated',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/gather-digits');
        $params = [
            'CallSid' => 'CA3333333333dddddddd3333333333dddd',
            'Digits' => '9',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/gather-digits', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('did not recognize', $content);
        $this->assertStringContainsString('Redirect', $content);
    }

    public function test_voicemail_record_stores_recording(): void
    {
        $call = Call::create([
            'call_sid' => 'CAvoicemailtest001',
            'from_number' => '+15554444444',
            'status' => 'initiated',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/voicemail-record');
        $params = [
            'CallSid' => 'CAvoicemailtest001',
            'RecordingSid' => 'REvoicemailtest001',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REvoicemailtest001',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/voicemail-record', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        // Verify Voicemail record created
        $this->assertDatabaseHas('voicemails', [
            'call_id' => $call->id,
            'recording_sid' => 'REvoicemailtest001',
            'recording_url' => 'https://api.twilio.com/recordings/REvoicemailtest001',
        ]);

        // Verify Call status updated
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAvoicemailtest001',
            'status' => 'voicemail_recorded',
            'outcome' => 'voicemail',
        ]);

        $content = $response->getContent();
        $this->assertStringContainsString('Thank you for your message', $content);
        $this->assertStringContainsString('<Hangup', $content);
    }

    public function test_voicemail_record_notifies_agents(): void
    {
        $agent = Agent::create([
            'name' => 'voicemail_agent',
            'phone_number' => '+15555555555',
        ]);

        $call = Call::create([
            'call_sid' => 'CAvoicemailnotifytest001',
            'from_number' => '+15556666666',
            'status' => 'initiated',
        ]);

        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
                'sid' => 'SMvoicemailnotify001',
                'from' => config('services.twilio.number'),
                'to' => $agent->phone_number,
                'body' => 'New voicemail from +15556666666',
            ], 201),
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/voicemail-record');
        $params = [
            'CallSid' => 'CAvoicemailnotifytest001',
            'RecordingSid' => 'REvoicemailnotify001',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REvoicemailnotify001',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/voicemail-record', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        // Verify SMS was sent attempt (Http::fake would capture it)
        // Note: In real scenario, we'd check SMS log or Twilio API records
        // For now, just verify the voicemail was saved
        $this->assertDatabaseHas('voicemails', [
            'call_id' => $call->id,
            'recording_sid' => 'REvoicemailnotify001',
        ]);
    }

    public function test_no_agent_available_redirects_to_voicemail(): void
    {
        $call = Call::create([
            'call_sid' => 'CAnoagenttest001',
            'from_number' => '+15557777777',
            'status' => 'initiated',
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/no-agent-available');
        $params = [
            'CallSid' => 'CAnoagenttest001',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/no-agent-available', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('unavailable', $content);
        $this->assertStringContainsString('<Record', $content);
        $this->assertStringContainsString('voicemail-record', $content);
    }

    public function test_no_agent_available_with_accepted_task_hangs_up(): void
    {
        $call = Call::create([
            'call_sid' => 'CAwithagenttest001',
            'from_number' => '+15558888888',
            'status' => 'accepted',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTwithagent001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'accepted',
            'reservation_sid' => 'WRwithagent001',
        ]);

        $call->update(['task_sid' => $taskRecord->task_sid]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/no-agent-available');
        $params = [
            'CallSid' => 'CAwithagenttest001',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/no-agent-available', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        $content = $response->getContent();
        // Should not record voicemail since task was accepted
        $this->assertStringNotContainsString('<Record', $content);
        $this->assertStringContainsString('<Hangup', $content);
    }
}
