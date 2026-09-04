<?php

namespace Tests\Feature\Agents;

use App\Services\Agents\NumberReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NumberReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const AR_NUMBER = '+5491166716882';

    private function fake(array $overrides = []): void
    {
        Http::fake(array_merge([
            'https://lookups.twilio.com/*' => Http::response([
                'phone_number' => self::AR_NUMBER,
                'country_code' => 'AR',
                'valid' => true,
            ], 200),
            'https://voice.twilio.com/v1/DialingPermissions/Countries*' => Http::response([
                'content' => [[
                    'iso_code' => 'AR',
                    'name' => 'Argentina',
                    'low_risk_numbers_enabled' => false,
                ]],
                'meta' => ['key' => 'content'],
            ], 200),
            'https://api.twilio.com/2010-04-01/Accounts/*/OutgoingCallerIds.json*' => Http::response([
                'outgoing_caller_ids' => [],
                'meta' => ['key' => 'outgoing_caller_ids'],
            ], 200),
        ], $overrides));
    }

    public function test_it_reports_a_country_that_is_not_authorised(): void
    {
        $this->fake();

        $result = app(NumberReadiness::class)->inspect(self::AR_NUMBER);

        $this->assertTrue($result['valid']);
        $this->assertSame('AR', $result['countryCode']);
        $this->assertSame('Argentina', $result['countryName']);
        $this->assertFalse($result['callingEnabled']);
        $this->assertFalse($result['verifiedCallerId']);
    }

    public function test_it_reports_an_authorised_and_verified_number(): void
    {
        $this->fake([
            'https://voice.twilio.com/v1/DialingPermissions/Countries*' => Http::response([
                'content' => [[
                    'iso_code' => 'AR',
                    'name' => 'Argentina',
                    'low_risk_numbers_enabled' => true,
                ]],
                'meta' => ['key' => 'content'],
            ], 200),
            'https://api.twilio.com/2010-04-01/Accounts/*/OutgoingCallerIds.json*' => Http::response([
                'outgoing_caller_ids' => [['phone_number' => self::AR_NUMBER]],
                'meta' => ['key' => 'outgoing_caller_ids'],
            ], 200),
        ]);

        $result = app(NumberReadiness::class)->inspect(self::AR_NUMBER);

        $this->assertTrue($result['callingEnabled']);
        $this->assertTrue($result['verifiedCallerId']);
    }

    public function test_an_invalid_number_short_circuits_the_permission_lookups(): void
    {
        $this->fake([
            'https://lookups.twilio.com/*' => Http::response([
                'phone_number' => 'nope',
                'country_code' => null,
                'valid' => false,
            ], 200),
        ]);

        $result = app(NumberReadiness::class)->inspect('nope');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['callingEnabled']);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'DialingPermissions'));
    }

    /**
     * A readiness check is advisory. If Twilio is unreachable the form should still work, just
     * without the warning.
     */
    public function test_a_failing_lookup_degrades_instead_of_throwing(): void
    {
        Http::fake(['https://lookups.twilio.com/*' => Http::response([], 500)]);

        $result = app(NumberReadiness::class)->inspect(self::AR_NUMBER);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['countryCode']);
    }

    public function test_enable_country_turns_on_low_risk_numbers_only(): void
    {
        Http::fake(['https://voice.twilio.com/*' => Http::response(['update_count' => 1], 201)]);

        app(NumberReadiness::class)->enableCountry('AR');

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request['UpdateRequest'], true);

            return str_contains($request->url(), 'BulkCountryUpdates')
                && $payload[0]['iso_code'] === 'AR'
                && $payload[0]['low_risk_numbers_enabled'] === true
                && ! array_key_exists('high_risk_special_numbers_enabled', $payload[0])
                && ! array_key_exists('high_risk_tollfraud_numbers_enabled', $payload[0]);
        });
    }
}
