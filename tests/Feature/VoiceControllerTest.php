<?php

namespace Tests\Feature;

use App\Models\Call;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceControllerTest extends TestCase
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

    public function test_gather_digits_press_1_routes_to_agent(): void
    {
        $call = Call::create([
            'call_sid' => 'CA1111111111bbbbbbbb1111111111bbbb',
            'from_number' => '+15551111111',
            'status' => 'initiated',
        ]);

        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response([
                'sid' => 'WTtestagenttask001',
                'workflowSid' => config('services.twilio.workflow_sid'),
                'attributes' => '{"callSid":"CA1111111111bbbbbbbb1111111111bbbb","from":"+15551111111"}',
                'status' => 'pending',
            ], 201),
        ]);

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/gather-digits');
        $params = [
            'CallSid' => 'CA1111111111bbbbbbbb1111111111bbbb',
            'Digits' => '1',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/gather-digits', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<Enqueue', $content);
        $this->assertStringContainsString('workflowSid', $content);

        // Verify Call and TaskRecord were updated
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CA1111111111bbbbbbbb1111111111bbbb',
            'task_sid' => 'WTtestagenttask001',
        ]);
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTtestagenttask001',
            'call_id' => $call->id,
            'status' => 'pending',
        ]);
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
}


