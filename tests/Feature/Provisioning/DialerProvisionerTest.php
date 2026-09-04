<?php

namespace Tests\Feature\Provisioning;

use App\Services\Provisioning\DialerProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DialerProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://example.ngrok-free.app';

    private const APPLICATION_SID = 'APTEST0000000000000000000000001';

    public function test_it_creates_the_twiml_application_when_none_exists(): void
    {
        Http::fake(function (Request $request) {
            $method = $request->method();
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/Applications.json') && $method === 'GET' => Http::response([
                    'applications' => [], 'meta' => ['key' => 'applications'],
                ]),
                str_ends_with($path, '/Applications.json') && $method === 'POST' => Http::response([
                    'sid' => self::APPLICATION_SID, 'friendly_name' => 'Browser Dialer',
                ], 201),
                default => Http::response(['message' => "Unexpected fake request: {$method} {$path}"], 500),
            };
        });

        $result = app(DialerProvisioner::class)->provision(self::APP_URL);

        $this->assertSame(self::APPLICATION_SID, $result['twimlAppSid']);
        $this->assertTrue($result['created']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/Applications.json')
            && $request['VoiceUrl'] === self::APP_URL.'/api/dialer/outbound');
    }

    public function test_it_reuses_the_existing_application_and_refreshes_its_voice_url(): void
    {
        Http::fake(function (Request $request) {
            $method = $request->method();
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/Applications.json') && $method === 'GET' => Http::response([
                    'applications' => [['sid' => self::APPLICATION_SID, 'friendly_name' => 'Browser Dialer']],
                    'meta' => ['key' => 'applications'],
                ]),
                str_ends_with($path, '/Applications/'.self::APPLICATION_SID.'.json') && $method === 'POST' => Http::response([
                    'sid' => self::APPLICATION_SID, 'friendly_name' => 'Browser Dialer',
                ]),
                default => Http::response(['message' => "Unexpected fake request: {$method} {$path}"], 500),
            };
        });

        $result = app(DialerProvisioner::class)->provision(self::APP_URL);

        $this->assertSame(self::APPLICATION_SID, $result['twimlAppSid']);
        $this->assertFalse($result['created']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/Applications/'.self::APPLICATION_SID.'.json')
            && $request['VoiceUrl'] === self::APP_URL.'/api/dialer/outbound');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/Applications.json'));
    }

    public function test_it_returns_the_api_key_secret_once(): void
    {
        Http::fake([
            '*/Keys.json' => Http::response([
                'sid' => 'SKTEST0000000000000000000000001',
                'secret' => 'super-secret-value',
                'friendly_name' => 'Browser Dialer',
            ], 201),
        ]);

        $key = app(DialerProvisioner::class)->createApiKey();

        $this->assertSame('SKTEST0000000000000000000000001', $key['sid']);
        $this->assertSame('super-secret-value', $key['secret']);
    }
}
