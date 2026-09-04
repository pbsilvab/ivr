<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentCreationTest extends TestCase
{
    use RefreshDatabase;

    private const WORKSPACE_SID = 'WSTEST0000000000000000000000001';

    private const UNAVAILABLE_SID = 'WATESTUNAVAILABLE000000000000001';

    private const WORKER_SID = 'WKNEWAGENT000000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.workspace_sid' => self::WORKSPACE_SID,
            'services.twilio.activity_unavailable_sid' => self::UNAVAILABLE_SID,
        ]);
    }

    private function fakeTwilio(): void
    {
        Http::fake([
            'https://lookups.twilio.com/*' => Http::response([
                'phone_number' => '+5491166716882',
                'country_code' => 'AR',
                'valid' => true,
            ], 200),
            'https://voice.twilio.com/*' => Http::response(['update_count' => 1], 201),
            'https://taskrouter.twilio.com/*' => Http::response([
                'sid' => self::WORKER_SID,
                'friendly_name' => 'Nueva Agente',
            ], 201),
        ]);
    }

    public function test_it_creates_the_agent_and_its_taskrouter_worker(): void
    {
        $this->fakeTwilio();

        $response = $this->postJson('/api/agents', [
            'name' => 'Nueva Agente',
            'phone_number' => '+5491166716882',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('twilio_worker_sid', self::WORKER_SID);

        $this->assertDatabaseHas('agents', [
            'name' => 'Nueva Agente',
            'phone_number' => '+5491166716882',
            'twilio_worker_sid' => self::WORKER_SID,
            'status' => 'unavailable',
        ]);

        // contact_uri is what the dequeue instruction dials.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/Workers')
            && str_contains($request['Attributes'], '+5491166716882'));
    }

    public function test_it_leaves_geographic_permissions_alone_unless_asked(): void
    {
        $this->fakeTwilio();

        $this->postJson('/api/agents', [
            'name' => 'Sin permisos',
            'phone_number' => '+5491166716882',
        ])->assertStatus(201);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'BulkCountryUpdates'));
    }

    public function test_it_authorises_the_country_when_asked(): void
    {
        $this->fakeTwilio();

        $this->postJson('/api/agents', [
            'name' => 'Con permisos',
            'phone_number' => '+5491166716882',
            'enable_country' => true,
        ])->assertStatus(201);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'BulkCountryUpdates')) {
                return false;
            }

            return json_decode($request['UpdateRequest'], true)[0]['iso_code'] === 'AR';
        });
    }

    /**
     * The Worker call is the one that talks to Twilio, so it is the one that can fail. The agent
     * has to survive it — the console renders that row with a disabled toggle and says why.
     */
    public function test_the_agent_survives_a_failed_worker_provisioning(): void
    {
        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response(['message' => 'nope'], 500),
        ]);

        $response = $this->postJson('/api/agents', [
            'name' => 'Sin worker',
            'phone_number' => '+15550001111',
        ]);

        $response->assertStatus(502);
        $response->assertJsonPath('agent.name', 'Sin worker');

        $this->assertDatabaseHas('agents', [
            'name' => 'Sin worker',
            'twilio_worker_sid' => null,
        ]);
    }

    public function test_it_rejects_a_number_that_is_not_e164(): void
    {
        $this->postJson('/api/agents', [
            'name' => 'Mal numero',
            'phone_number' => '1166716882',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');

        $this->assertSame(0, Agent::count());
    }

    public function test_it_rejects_a_duplicate_number(): void
    {
        Agent::create(['name' => 'Existente', 'phone_number' => '+15550001111']);

        $this->postJson('/api/agents', [
            'name' => 'Repetida',
            'phone_number' => '+15550001111',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');

        $this->assertSame(1, Agent::count());
    }

    public function test_number_check_reports_the_country_permissions(): void
    {
        Http::fake([
            'https://lookups.twilio.com/*' => Http::response([
                'phone_number' => '+5491166716882',
                'country_code' => 'AR',
                'valid' => true,
            ], 200),
            'https://voice.twilio.com/v1/DialingPermissions/Countries*' => Http::response([
                'content' => [['iso_code' => 'AR', 'name' => 'Argentina', 'low_risk_numbers_enabled' => false]],
                'meta' => ['key' => 'content'],
            ], 200),
            'https://api.twilio.com/*' => Http::response([
                'outgoing_caller_ids' => [],
                'meta' => ['key' => 'outgoing_caller_ids'],
            ], 200),
        ]);

        $response = $this->postJson('/api/agents/number-check', ['phone_number' => '+5491166716882']);

        $response->assertStatus(200);
        $response->assertJson([
            'valid' => true,
            'countryCode' => 'AR',
            'countryName' => 'Argentina',
            'callingEnabled' => false,
            'verifiedCallerId' => false,
        ]);
    }
}
