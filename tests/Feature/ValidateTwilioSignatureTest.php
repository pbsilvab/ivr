<?php

namespace Tests\Feature;

use Tests\TestCase;

class ValidateTwilioSignatureTest extends TestCase
{
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

    public function test_valid_signature_passes(): void
    {
        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/voice/incoming');
        $params = [
            'CallSid' => 'CA1234567890',
            'From' => '+15551234567',
        ];

        $signature = $this->computeSignature($url, $params, $authToken);

        $response = $this->post('/api/voice/incoming', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        // Endpoint not yet implemented, but middleware should pass (won't be 403)
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_invalid_signature_returns_403(): void
    {
        $params = [
            'CallSid' => 'CA1234567890',
            'From' => '+15551234567',
        ];

        $response = $this->post('/api/voice/incoming', $params, [
            'X-Twilio-Signature' => 'invalid_signature_here',
        ]);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_missing_signature_returns_403(): void
    {
        $params = [
            'CallSid' => 'CA1234567890',
            'From' => '+15551234567',
        ];

        $response = $this->post('/api/voice/incoming', $params);

        $this->assertEquals(403, $response->getStatusCode());
    }
}
