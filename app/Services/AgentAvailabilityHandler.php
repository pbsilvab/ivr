<?php

namespace App\Services;

use App\Models\Agent;
use Twilio\Rest\Client;

class AgentAvailabilityHandler
{
    public function __construct(private Client $twilioClient) {}

    /**
     * Re-read every Worker's Activity from TaskRouter into the local `status` column.
     *
     * TaskRouter moves Workers on its own — a reservation that times out sends them to the
     * Workspace's timeout Activity — so the local column drifts and the console ends up showing
     * an agent as Available while Twilio has already taken them out of rotation.
     */
    public function syncStatuses(): void
    {
        $workspaceSid = config('services.twilio.workspace_sid');
        $availableSid = config('services.twilio.activity_available_sid');

        $workers = collect($this->twilioClient->taskrouter->v1->workspaces($workspaceSid)->workers->read())
            ->keyBy('sid');

        foreach (Agent::whereNotNull('twilio_worker_sid')->get() as $agent) {
            $worker = $workers->get($agent->twilio_worker_sid);

            if (! $worker) {
                continue;
            }

            $status = $worker->activitySid === $availableSid ? 'available' : 'unavailable';

            if ($agent->status !== $status) {
                $agent->update(['status' => $status]);
            }
        }
    }

    public function toggleAvailability(int $agentId): array
    {
        $agent = Agent::findOrFail($agentId);

        if (! $agent->twilio_worker_sid) {
            throw new \InvalidArgumentException("Agent {$agentId} is not provisioned in Twilio.");
        }

        $workspaceSid = config('services.twilio.workspace_sid');
        $activityAvailableSid = config('services.twilio.activity_available_sid');
        $activityUnavailableSid = config('services.twilio.activity_unavailable_sid');

        // Determine new activity based on current status
        $newStatus = $agent->status === 'available' ? 'unavailable' : 'available';
        $newActivitySid = $newStatus === 'available' ? $activityAvailableSid : $activityUnavailableSid;

        // Update activity in Twilio
        $worker = $this->twilioClient->taskrouter->v1->workspaces($workspaceSid)
            ->workers($agent->twilio_worker_sid)
            ->update(['activitySid' => $newActivitySid]);

        // Update local agent status
        $agent->update(['status' => $newStatus]);

        return [
            'agent_id' => $agent->id,
            'name' => $agent->name,
            'status' => $newStatus,
            'twilio_activity_sid' => $worker->activitySid,
        ];
    }

    public function setAvailability(int $agentId, string $status): array
    {
        if (! in_array($status, ['available', 'unavailable'])) {
            throw new \InvalidArgumentException("Status must be 'available' or 'unavailable'.");
        }

        $agent = Agent::findOrFail($agentId);

        if (! $agent->twilio_worker_sid) {
            throw new \InvalidArgumentException("Agent {$agentId} is not provisioned in Twilio.");
        }

        // Only update if different from current status
        if ($agent->status === $status) {
            return [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'status' => $status,
                'message' => 'Agent status unchanged.',
            ];
        }

        return $this->toggleAvailability($agentId);
    }
}
