<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\Provisioning\TaskRouterProvisioner;
use Illuminate\Console\Command;

class ProvisionTaskRouterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'taskrouter:provision';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or reuse the TaskRouter Workspace, Activities, TaskQueue, Workflow and Workers';

    /**
     * Execute the console command.
     */
    public function handle(TaskRouterProvisioner $provisioner): int
    {
        $this->ensureAtLeastOneAgentExists();

        $appUrl = rtrim((string) config('app.url'), '/');

        $result = $provisioner->provision($appUrl);

        $this->table(['Resource', 'SID'], [
            ['Workspace', $result['workspaceSid']],
            ['Activity: Available', $result['availableSid']],
            ['Activity: Unavailable', $result['unavailableSid']],
            ['TaskQueue', $result['taskQueueSid']],
            ['Workflow', $result['workflowSid']],
        ]);

        if ($result['workers'] === []) {
            $this->info('No new agents needed a Worker.');
        } else {
            $this->table(['Agent ID', 'Worker SID'], array_map(
                fn (array $worker) => [$worker['agent_id'], $worker['worker_sid']],
                $result['workers'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * First-run convenience: a Worker can only be created for an existing Agent, so prompt
     * for one instead of silently provisioning a Workspace with nobody to route calls to.
     */
    private function ensureAtLeastOneAgentExists(): void
    {
        if (Agent::count() > 0) {
            return;
        }

        $this->warn('No agents found yet — at least one is needed to create a TaskRouter Worker.');

        if (! $this->confirm('Create an agent now?', true)) {
            return;
        }

        $agent = Agent::create([
            'name' => $this->ask('Agent name'),
            'phone_number' => $this->ask('Agent phone number (E.164, e.g. +15551234567)'),
        ]);

        $this->info("Created agent #{$agent->id} ({$agent->name}).");
    }
}


