<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentConsoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The console reads live state from Twilio on every render, so every test here has to fake it
     * — otherwise the suite makes real API calls.
     *
     * @param  list<string>  $verified
     */
    private function fakeTwilio(array $workers = [], array $verified = []): void
    {
        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response([
                'workers' => $workers,
                'meta' => ['key' => 'workers'],
            ], 200),
            'https://api.twilio.com/2010-04-01/Accounts/*/OutgoingCallerIds.json*' => Http::response([
                'outgoing_caller_ids' => array_map(fn (string $n): array => ['phone_number' => $n], $verified),
                'meta' => ['key' => 'outgoing_caller_ids'],
            ], 200),
        ]);
    }

    public function test_it_lists_agents_with_their_current_status(): void
    {
        $this->fakeTwilio(verified: ['+15550001111', '+15550002222']);

        Agent::create([
            'name' => 'Ada',
            'phone_number' => '+15550001111',
            'twilio_worker_sid' => 'WKTEST0000000000000000000000001',
            'status' => 'available',
        ]);

        Agent::create([
            'name' => 'Grace',
            'phone_number' => '+15550002222',
            'twilio_worker_sid' => 'WKTEST0000000000000000000000002',
            'status' => 'unavailable',
        ]);

        $response = $this->withoutVite()->get('/agents');

        $response->assertStatus(200);
        $response->assertSee('Ada');
        $response->assertSee('Grace');
        $response->assertSee('Available');
        $response->assertSee('Unavailable');
        $response->assertDontSee('Number not verified');
    }

    public function test_it_disables_the_toggle_for_an_agent_without_a_worker(): void
    {
        $this->fakeTwilio(verified: ['+15550003333']);

        Agent::create(['name' => 'Unprovisioned', 'phone_number' => '+15550003333']);

        $response = $this->withoutVite()->get('/agents');

        $response->assertStatus(200);
        $response->assertSee('No Twilio Worker', false);
        $this->assertStringContainsString('disabled', $response->getContent());
    }

    public function test_it_flags_an_agent_whose_number_is_not_a_verified_caller_id(): void
    {
        $this->fakeTwilio(verified: ['+15559999999']);

        Agent::create([
            'name' => 'Unverified',
            'phone_number' => '+5491130754261',
            'twilio_worker_sid' => 'WKTEST0000000000000000000000003',
        ]);

        $response = $this->withoutVite()->get('/agents');

        $response->assertStatus(200);
        $response->assertSee('Number not verified', false);
        $response->assertSee('21219');
        $response->assertSee('data-verify', false);
    }

    public function test_it_renders_an_empty_state_with_no_agents(): void
    {
        $this->fakeTwilio();

        $response = $this->withoutVite()->get('/agents');

        $response->assertStatus(200);
        $response->assertSee('No agents yet');
    }

    public function test_verify_number_asks_twilio_to_call_the_agent(): void
    {
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/OutgoingCallerIds.json' => Http::response([
                'phone_number' => '+5491130754261',
                'friendly_name' => 'Valeria',
                'validation_code' => '482915',
                'call_sid' => 'CAverify0000000000000000000000001',
            ], 201),
        ]);

        $agent = Agent::create(['name' => 'Valeria', 'phone_number' => '+5491130754261']);

        $response = $this->postJson("/api/agents/{$agent->id}/verify-number");

        $response->assertStatus(200);
        $response->assertJson([
            'code' => '482915',
            'callSid' => 'CAverify0000000000000000000000001',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'OutgoingCallerIds.json')
            && $request['PhoneNumber'] === '+5491130754261');
    }

    /**
     * Trial accounts cannot place verification calls, which is exactly the kind of account that
     * needs verified numbers. The response has to point at the Console instead of stopping at the
     * raw API error.
     */
    public function test_verify_number_hands_over_the_console_path_when_twilio_refuses(): void
    {
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/OutgoingCallerIds.json' => Http::response([
                'code' => 21470,
                'message' => 'Placing verification calls is not supported on trial accounts.',
            ], 400),
        ]);

        $agent = Agent::create(['name' => 'Valeria', 'phone_number' => '+5491130754261']);

        $response = $this->postJson("/api/agents/{$agent->id}/verify-number");

        $response->assertStatus(502);
        $this->assertStringContainsString('trial accounts', $response->json('error'));
        $this->assertStringContainsString('Verified Caller IDs', $response->json('hint'));
    }

    public function test_verify_number_404s_for_an_unknown_agent(): void
    {
        Http::fake();

        $this->postJson('/api/agents/999/verify-number')->assertStatus(404);

        Http::assertNothingSent();
    }
}
