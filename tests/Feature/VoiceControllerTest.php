<?php

namespace Tests\Feature;

use App\Models\Call;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

