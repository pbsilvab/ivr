<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Services\Provisioning\TaskRouterProvisioner;

class CreateAgentAction
{
    public function __construct(
        private readonly TaskRouterProvisioner $provisioner,
        private readonly NumberReadiness $numbers,
    ) {}

    /**
     * Create an agent and give it a TaskRouter Worker, optionally authorising calls to its
     * country first.
     *
     * If the Worker cannot be created the agent is left in place without a `twilio_worker_sid`
     * and the error propagates. None of this is transactional against Twilio, and the console
     * already renders that state with a disabled toggle — deleting the row would just hide what
     * went wrong.
     */
    public function handle(string $name, string $phoneNumber, bool $enableCountry = false): Agent
    {
        $agent = Agent::create([
            'name' => $name,
            'phone_number' => $phoneNumber,
            'status' => 'unavailable',
        ]);

        if ($enableCountry) {
            $countryCode = $this->numbers->inspect($phoneNumber)['countryCode'];

            if ($countryCode !== null) {
                $this->numbers->enableCountry($countryCode);
            }
        }

        $this->provisioner->provisionWorker(
            $agent,
            config('services.twilio.workspace_sid'),
            config('services.twilio.activity_unavailable_sid'),
        );

        return $agent->refresh();
    }
}
