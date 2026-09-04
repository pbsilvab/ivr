<?php

namespace App\Services\Provisioning;

use App\Models\Agent;
use App\Models\Setting;
use RuntimeException;
use Twilio\Rest\Client;

class TaskRouterProvisioner
{
    private const WORKSPACE_NAME = 'Voice Workspace';

    private const TASK_QUEUE_NAME = 'Everyone';

    private const WORKFLOW_NAME = 'Voice Workflow';

    private const ACTIVITY_AVAILABLE = 'Available';

    private const ACTIVITY_UNAVAILABLE = 'Unavailable';

    public function __construct(private readonly Client $client) {}

    /**
     * Create or reuse the TaskRouter resources needed to route calls, and provision a
     * Worker for every local Agent that doesn't have one yet. Safe to run repeatedly.
     *
     * @return array{
     *     workspaceSid: string,
     *     availableSid: string,
     *     unavailableSid: string,
     *     taskQueueSid: string,
     *     workflowSid: string,
     *     workers: list<array{agent_id: int, worker_sid: string}>,
     * }
     */
    public function provision(string $appUrl): array
    {
        $workspaceSid = $this->resolveWorkspace($appUrl);
        [$availableSid, $unavailableSid] = $this->resolveActivities($workspaceSid);
        $taskQueueSid = $this->resolveTaskQueue($workspaceSid);
        $workflowSid = $this->resolveWorkflow($workspaceSid, $taskQueueSid, $appUrl);
        $workers = $this->provisionWorkers($workspaceSid, $unavailableSid);

        Setting::set('twilio_workspace_sid', $workspaceSid);
        Setting::set('twilio_activity_available_sid', $availableSid);
        Setting::set('twilio_activity_unavailable_sid', $unavailableSid);
        Setting::set('twilio_task_queue_sid', $taskQueueSid);
        Setting::set('twilio_workflow_sid', $workflowSid);

        return [
            'workspaceSid' => $workspaceSid,
            'availableSid' => $availableSid,
            'unavailableSid' => $unavailableSid,
            'taskQueueSid' => $taskQueueSid,
            'workflowSid' => $workflowSid,
            'workers' => $workers,
        ];
    }

    private function resolveWorkspace(string $appUrl): string
    {
        $existing = $this->client->taskrouter->v1->workspaces
            ->read(['friendlyName' => self::WORKSPACE_NAME], 1);

        if ($existing !== []) {
            return $existing[0]->sid;
        }

        return $this->client->taskrouter->v1->workspaces
            ->create(self::WORKSPACE_NAME, [
                'eventCallbackUrl' => "{$appUrl}/api/taskrouter/events",
                'multiTaskEnabled' => true,
            ])
            ->sid;
    }

    /**
     * Every new Workspace already ships with these two Activities by default, so we only
     * look them up here — creating them would just duplicate what Twilio already provides.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveActivities(string $workspaceSid): array
    {
        $activities = $this->client->taskrouter->v1->workspaces($workspaceSid)->activities->read();

        $available = collect($activities)->firstWhere('friendlyName', self::ACTIVITY_AVAILABLE);
        $unavailable = collect($activities)->firstWhere('friendlyName', self::ACTIVITY_UNAVAILABLE);

        if (! $available || ! $unavailable) {
            throw new RuntimeException('Default Available/Unavailable activities were not found on the Workspace.');
        }

        return [$available->sid, $unavailable->sid];
    }

    private function resolveTaskQueue(string $workspaceSid): string
    {
        $existing = $this->client->taskrouter->v1->workspaces($workspaceSid)->taskQueues
            ->read(['friendlyName' => self::TASK_QUEUE_NAME], 1);

        if ($existing !== []) {
            return $existing[0]->sid;
        }

        return $this->client->taskrouter->v1->workspaces($workspaceSid)->taskQueues
            ->create(self::TASK_QUEUE_NAME, ['targetWorkers' => '1==1'])
            ->sid;
    }

    private function resolveWorkflow(string $workspaceSid, string $taskQueueSid, string $appUrl): string
    {
        // Single filter with a timeout so an unanswered call falls back to voicemail instead of waiting forever.
        $configuration = json_encode([
            'task_routing' => [
                'filters' => [
                    [
                        'filter_friendly_name' => 'Voice calls',
                        'expression' => '1==1',
                        'targets' => [
                            ['queue' => $taskQueueSid, 'timeout' => 20],
                        ],
                    ],
                ],
                'default_filter' => ['queue' => $taskQueueSid],
            ],
        ]);

        $assignmentCallbackUrl = "{$appUrl}/api/taskrouter/assignment";

        $existing = $this->client->taskrouter->v1->workspaces($workspaceSid)->workflows
            ->read(['friendlyName' => self::WORKFLOW_NAME], 1);

        if ($existing !== []) {
            $this->client->taskrouter->v1->workspaces($workspaceSid)->workflows($existing[0]->sid)
                ->update([
                    'assignmentCallbackUrl' => $assignmentCallbackUrl,
                    'configuration' => $configuration,
                ]);

            return $existing[0]->sid;
        }

        return $this->client->taskrouter->v1->workspaces($workspaceSid)->workflows
            ->create(self::WORKFLOW_NAME, $configuration, ['assignmentCallbackUrl' => $assignmentCallbackUrl])
            ->sid;
    }

    /**
     * Create the TaskRouter Worker for one Agent and persist its SID.
     *
     * `contact_uri` is what the assignment callback's `dequeue` instruction dials, so it has to
     * be the agent's real number.
     */
    public function provisionWorker(Agent $agent, string $workspaceSid, string $activitySid): string
    {
        $worker = $this->client->taskrouter->v1->workspaces($workspaceSid)->workers
            ->create($agent->name, [
                'attributes' => json_encode(['contact_uri' => $agent->phone_number]),
                'activitySid' => $activitySid,
            ]);

        $agent->update(['twilio_worker_sid' => $worker->sid]);

        return $worker->sid;
    }

    /**
     * @return list<array{agent_id: int, worker_sid: string}>
     */
    private function provisionWorkers(string $workspaceSid, string $unavailableActivitySid): array
    {
        $provisioned = [];

        foreach (Agent::whereNull('twilio_worker_sid')->get() as $agent) {
            $provisioned[] = [
                'agent_id' => $agent->id,
                'worker_sid' => $this->provisionWorker($agent, $workspaceSid, $unavailableActivitySid),
            ];
        }

        return $provisioned;
    }
}
