<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_agents_with_their_current_status(): void
    {
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
    }

    public function test_it_disables_the_toggle_for_an_agent_without_a_worker(): void
    {
        Agent::create(['name' => 'Unprovisioned', 'phone_number' => '+15550003333']);

        $response = $this->withoutVite()->get('/agents');

        $response->assertStatus(200);
        $response->assertSee('No Twilio Worker', false);
        $this->assertStringContainsString('disabled', $response->getContent());
    }

    public function test_it_renders_an_empty_state_with_no_agents(): void
    {
        $response = $this->withoutVite()->get('/agents');

        $response->assertStatus(200);
        $response->assertSee('No agents yet');
    }
}
