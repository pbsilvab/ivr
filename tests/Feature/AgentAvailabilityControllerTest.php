<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentAvailabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_availability_from_unavailable_to_available(): void
    {
        $agent = Agent::create([
            'name' => 'toggle_test_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKtoggletestav001',
            'status' => 'unavailable',
        ]);

        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response([
                'sid' => $agent->twilio_worker_sid,
                'activitySid' => config('services.twilio.activity_available_sid'),
                'name' => $agent->name,
            ], 200),
        ]);

        $response = $this->post("/api/agents/{$agent->id}/availability/toggle");

        $response->assertStatus(200);
        $response->assertJson([
            'agent_id' => $agent->id,
            'name' => 'toggle_test_agent',
            'status' => 'available',
        ]);

        $this->assertDatabaseHas('agents', [
            'id' => $agent->id,
            'status' => 'available',
        ]);
    }

    public function test_toggle_availability_from_available_to_unavailable(): void
    {
        $agent = Agent::create([
            'name' => 'toggle_test_agent_2',
            'phone_number' => '+15552222222',
            'twilio_worker_sid' => 'WKtoggletestav002',
            'status' => 'available',
        ]);

        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response([
                'sid' => $agent->twilio_worker_sid,
                'activitySid' => config('services.twilio.activity_unavailable_sid'),
                'name' => $agent->name,
            ], 200),
        ]);

        $response = $this->post("/api/agents/{$agent->id}/availability/toggle");

        $response->assertStatus(200);
        $response->assertJson([
            'agent_id' => $agent->id,
            'status' => 'unavailable',
        ]);

        $this->assertDatabaseHas('agents', [
            'id' => $agent->id,
            'status' => 'unavailable',
        ]);
    }

    public function test_toggle_availability_for_non_provisioned_agent(): void
    {
        $agent = Agent::create([
            'name' => 'non_provisioned_agent',
            'phone_number' => '+15553333333',
            'twilio_worker_sid' => null,
            'status' => 'unavailable',
        ]);

        $response = $this->post("/api/agents/{$agent->id}/availability/toggle");

        $response->assertStatus(400);
        $response->assertJson(['error' => "Agent {$agent->id} is not provisioned in Twilio."]);
    }

    public function test_toggle_availability_for_unknown_agent(): void
    {
        $response = $this->post('/api/agents/9999/availability/toggle');

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Agent 9999 not found.']);
    }

    public function test_set_availability_to_available(): void
    {
        $agent = Agent::create([
            'name' => 'set_test_agent',
            'phone_number' => '+15554444444',
            'twilio_worker_sid' => 'WKsettestav001',
            'status' => 'unavailable',
        ]);

        Http::fake([
            'https://taskrouter.twilio.com/*' => Http::response([
                'sid' => $agent->twilio_worker_sid,
                'activitySid' => config('services.twilio.activity_available_sid'),
                'name' => $agent->name,
            ], 200),
        ]);

        $response = $this->post("/api/agents/{$agent->id}/availability/set", [
            'status' => 'available',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'agent_id' => $agent->id,
            'status' => 'available',
        ]);
    }

    public function test_set_availability_already_set(): void
    {
        $agent = Agent::create([
            'name' => 'set_test_agent_same',
            'phone_number' => '+15555555555',
            'twilio_worker_sid' => 'WKsettestav002',
            'status' => 'available',
        ]);

        $response = $this->post("/api/agents/{$agent->id}/availability/set", [
            'status' => 'available',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'agent_id' => $agent->id,
            'status' => 'available',
            'message' => 'Agent status unchanged.',
        ]);
    }

    public function test_set_availability_invalid_status(): void
    {
        $agent = Agent::create([
            'name' => 'set_test_agent_invalid',
            'phone_number' => '+15556666666',
            'twilio_worker_sid' => 'WKsettestav003',
            'status' => 'available',
        ]);

        $response = $this->postJson("/api/agents/{$agent->id}/availability/set", [
            'status' => 'invalid_status',
        ]);

        $response->assertStatus(422);
    }

    public function test_set_availability_missing_status(): void
    {
        $agent = Agent::create([
            'name' => 'set_test_agent_missing',
            'phone_number' => '+15557777777',
            'twilio_worker_sid' => 'WKsettestav004',
            'status' => 'available',
        ]);

        $response = $this->postJson("/api/agents/{$agent->id}/availability/set", []);

        $response->assertStatus(422);
    }
}

