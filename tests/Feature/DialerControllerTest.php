<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DialerControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TWIML_APP_SID = 'APTEST0000000000000000000000001';

    private const TWILIO_NUMBER = '+15550001111';

    /**
     * Twilio signs the URL plus every parameter as key+value, sorted by key.
     */
    private function computeSignature(string $url, array $params, string $authToken): string
    {
        ksort($params);

        $data = $url;

        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : $value);
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }

    private function configureDialer(): void
    {
        config([
            'services.twilio.sid' => 'ACTEST0000000000000000000000001',
            'services.twilio.number' => self::TWILIO_NUMBER,
            'services.twilio.twiml_app_sid' => self::TWIML_APP_SID,
            'services.twilio.api_key_sid' => 'SKTEST0000000000000000000000001',
            'services.twilio.api_key_secret' => 'test_api_key_secret',
            'services.twilio.dialer.caller_id' => self::TWILIO_NUMBER,
            'services.twilio.dialer.allowed_numbers' => [self::TWILIO_NUMBER],
            'services.twilio.dialer.token_ttl' => 3600,
        ]);
    }

    public function test_token_endpoint_returns_a_voice_grant_for_the_dialer_application(): void
    {
        $this->configureDialer();

        $response = $this->postJson('/api/dialer/token');

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'identity', 'ttl']);

        $identity = $response->json('identity');
        $this->assertStringStartsWith('dialer-', $identity);
        $this->assertSame(3600, $response->json('ttl'));

        [, $payload] = explode('.', $response->json('token'));
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        $this->assertSame($identity, $claims['grants']['identity']);
        $this->assertSame(self::TWIML_APP_SID, $claims['grants']['voice']['outgoing']['application_sid']);

        // Outgoing-only: a leaked token must not be able to receive calls.
        $this->assertArrayNotHasKey('incoming', $claims['grants']['voice']);
    }

    public function test_token_endpoint_sanitizes_a_caller_supplied_identity(): void
    {
        $this->configureDialer();

        $response = $this->postJson('/api/dialer/token', ['identity' => 'demo customer/../1']);

        $response->assertStatus(200);
        $this->assertSame('democustomer1', $response->json('identity'));
    }

    public function test_token_endpoint_reports_missing_credentials_instead_of_failing(): void
    {
        $this->configureDialer();
        config(['services.twilio.api_key_secret' => '']);

        $response = $this->postJson('/api/dialer/token');

        $response->assertStatus(503);
        $this->assertStringContainsString('TWILIO_API_KEY_SECRET', $response->json('error'));
    }

    public function test_outbound_dials_the_allowed_destination_with_the_configured_caller_id(): void
    {
        $this->configureDialer();

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/dialer/outbound');
        $params = [
            'CallSid' => 'CAdialertest0000000000000000000001',
            'From' => 'client:dialer-abcd1234',
            'To' => self::TWILIO_NUMBER,
        ];

        $response = $this->post('/api/dialer/outbound', $params, [
            'X-Twilio-Signature' => $this->computeSignature($url, $params, $authToken),
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('callerId="'.self::TWILIO_NUMBER.'"', $content);
        $this->assertStringContainsString('answerOnBridge="true"', $content);
        $this->assertStringContainsString('<Number>'.self::TWILIO_NUMBER.'</Number>', $content);
    }

    public function test_outbound_refuses_a_destination_outside_the_allowlist(): void
    {
        $this->configureDialer();

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/dialer/outbound');
        $params = [
            'CallSid' => 'CAdialertest0000000000000000000002',
            'From' => 'client:dialer-abcd1234',
            'To' => '+15559998888',
        ];

        $response = $this->post('/api/dialer/outbound', $params, [
            'X-Twilio-Signature' => $this->computeSignature($url, $params, $authToken),
        ]);

        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringNotContainsString('<Dial', $content);
        $this->assertStringContainsString('not allowed', $content);
        $this->assertStringContainsString('<Hangup', $content);
    }

    public function test_outbound_refuses_a_malformed_destination(): void
    {
        $this->configureDialer();

        $authToken = config('services.twilio.token') ?? 'test_token';
        $url = url('/api/dialer/outbound');
        $params = [
            'CallSid' => 'CAdialertest0000000000000000000003',
            'To' => 'not-a-number',
        ];

        $response = $this->post('/api/dialer/outbound', $params, [
            'X-Twilio-Signature' => $this->computeSignature($url, $params, $authToken),
        ]);

        $response->assertStatus(200);
        $this->assertStringNotContainsString('<Dial', $response->getContent());
    }

    /**
     * Behind ngrok the request reaches Laravel over plain HTTP, but Twilio signed the https URL
     * it actually requested. The signature only matches if the forwarded scheme is honoured.
     */
    public function test_outbound_validates_the_signature_against_the_forwarded_https_url(): void
    {
        $this->configureDialer();

        $authToken = config('services.twilio.token') ?? 'test_token';
        $params = [
            'CallSid' => 'CAdialertest0000000000000000000004',
            'To' => self::TWILIO_NUMBER,
        ];

        $signature = $this->computeSignature(secure_url('/api/dialer/outbound'), $params, $authToken);

        // trustProxies(at: '*') trusts whoever is calling, so the request needs a REMOTE_ADDR —
        // the test client does not set one, unlike a real request arriving from the ngrok agent.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->post('/api/dialer/outbound', $params, [
                'X-Twilio-Signature' => $signature,
                'X-Forwarded-Proto' => 'https',
            ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('<Dial', $response->getContent());
    }

    public function test_outbound_rejects_an_unsigned_request(): void
    {
        $this->configureDialer();

        $response = $this->post('/api/dialer/outbound', ['To' => self::TWILIO_NUMBER]);

        $response->assertStatus(403);
    }

    public function test_dialer_page_renders(): void
    {
        $this->configureDialer();

        $response = $this->withoutVite()->get('/dialer');

        $response->assertStatus(200);
        $response->assertSee(self::TWILIO_NUMBER);
        $response->assertSee('Softphone');
    }
}
