<?php

namespace Tests\Feature\Provisioning;

use App\Models\Agent;
use App\Services\Provisioning\TaskRouterProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskRouterProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://example.ngrok-free.app';

    private const WORKSPACE_SID = 'WSTEST0000000000000000000000001';

    private const AVAILABLE_SID = 'WATESTAVAILABLE0000000000000001';

    private const UNAVAILABLE_SID = 'WATESTUNAVAILABLE000000000000001';

    private const TASK_QUEUE_SID = 'WQTEST0000000000000000000000001';

    private const WORKFLOW_SID = 'WWTEST0000000000000000000000001';

    public function test_it_creates_all_resources_and_a_worker_for_a_pending_agent(): void
    {
        $agent = Agent::create(['name' => 'Test Agent', 'phone_number' => '+15550001111']);

        Http::fake(function (Request $request) {
            $method = $request->method();
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/v1/Workspaces' && $method === 'GET' => Http::response([
                    'workspaces' => [], 'meta' => ['key' => 'workspaces'],
                ]),
                $path === '/v1/Workspaces' && $method === 'POST' => Http::response([
                    'sid' => self::WORKSPACE_SID, 'friendly_name' => 'Voice Workspace',
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Activities' => Http::response([
                    'activities' => [
                        ['sid' => self::AVAILABLE_SID, 'friendly_name' => 'Available'],
                        ['sid' => self::UNAVAILABLE_SID, 'friendly_name' => 'Unavailable'],
                    ],
                    'meta' => ['key' => 'activities'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/TaskQueues' && $method === 'GET' => Http::response([
                    'task_queues' => [], 'meta' => ['key' => 'task_queues'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/TaskQueues' && $method === 'POST' => Http::response([
                    'sid' => self::TASK_QUEUE_SID, 'friendly_name' => 'Everyone',
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Workflows' && $method === 'GET' => Http::response([
                    'workflows' => [], 'meta' => ['key' => 'workflows'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Workflows' && $method === 'POST' => Http::response([
                    'sid' => self::WORKFLOW_SID, 'friendly_name' => 'Voice Workflow',
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Workers' && $method === 'POST' => Http::response([
                    'sid' => 'WKNEWWORKER00000000000000000001', 'friendly_name' => 'Test Agent',
                ]),
                str_ends_with($path, '/Channels/voice') => Http::response([
                    'sid' => 'WCTEST0000000000000000000000001',
                    'task_channel_unique_name' => 'voice',
                    'configured_capacity' => 1,
                ]),
                default => Http::response(['message' => "Unexpected fake request: {$method} {$path}"], 500),
            };
        });

        $result = app(TaskRouterProvisioner::class)->provision(self::APP_URL);

        $this->assertSame(self::WORKSPACE_SID, $result['workspaceSid']);
        $this->assertSame(self::AVAILABLE_SID, $result['availableSid']);
        $this->assertSame(self::UNAVAILABLE_SID, $result['unavailableSid']);
        $this->assertSame(self::TASK_QUEUE_SID, $result['taskQueueSid']);
        $this->assertSame(self::WORKFLOW_SID, $result['workflowSid']);
        $this->assertSame([
            ['agent_id' => $agent->id, 'worker_sid' => 'WKNEWWORKER00000000000000000001'],
        ], $result['workers']);

        $this->assertSame('WKNEWWORKER00000000000000000001', $agent->fresh()->twilio_worker_sid);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/v1/Workspaces');

        // Twilio defaults this to 1 anyway, but inheriting it leaves the value nowhere in the
        // repo and unrecoverable by re-running the command.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/Workers/WKNEWWORKER00000000000000000001/Channels/voice')
            && $request['Capacity'] === 1
            && $request['Available'] === 'true');
    }

    /**
     * A Worker provisioned before this step existed keeps whatever capacity it was given, so
     * re-running the command has to converge it rather than only touching new Workers.
     */
    public function test_it_also_sets_the_voice_capacity_of_workers_that_already_existed(): void
    {
        Agent::create([
            'name' => 'Already Provisioned',
            'phone_number' => '+15550002222',
            'twilio_worker_sid' => 'WKEXISTING000000000000000000001',
        ]);

        Http::fake(function (Request $request) {
            $method = $request->method();
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/v1/Workspaces' => Http::response([
                    'workspaces' => [['sid' => self::WORKSPACE_SID, 'friendly_name' => 'Voice Workspace']],
                    'meta' => ['key' => 'workspaces'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Activities' => Http::response([
                    'activities' => [
                        ['sid' => self::AVAILABLE_SID, 'friendly_name' => 'Available'],
                        ['sid' => self::UNAVAILABLE_SID, 'friendly_name' => 'Unavailable'],
                    ],
                    'meta' => ['key' => 'activities'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/TaskQueues' => Http::response([
                    'task_queues' => [['sid' => self::TASK_QUEUE_SID, 'friendly_name' => 'Everyone']],
                    'meta' => ['key' => 'task_queues'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Workflows' => Http::response([
                    'workflows' => [['sid' => self::WORKFLOW_SID, 'friendly_name' => 'Voice Workflow']],
                    'meta' => ['key' => 'workflows'],
                ]),
                default => Http::response([
                    'sid' => self::WORKFLOW_SID,
                    'task_channel_unique_name' => 'voice',
                    'configured_capacity' => 1,
                ]),
            };
        });

        app(TaskRouterProvisioner::class)->provision(self::APP_URL);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/Workers/WKEXISTING000000000000000000001/Channels/voice')
            && $request['Capacity'] === 1);
    }

    public function test_it_reuses_existing_resources_and_only_provisions_workers_for_pending_agents(): void
    {
        $alreadyProvisioned = Agent::create([
            'name' => 'Already Provisioned',
            'phone_number' => '+15550002222',
            'twilio_worker_sid' => 'WKEXISTING000000000000000000001',
        ]);
        $pending = Agent::create(['name' => 'Pending Agent', 'phone_number' => '+15550003333']);

        Http::fake(function (Request $request) {
            $method = $request->method();
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/v1/Workspaces' && $method === 'GET' => Http::response([
                    'workspaces' => [['sid' => self::WORKSPACE_SID, 'friendly_name' => 'Voice Workspace']],
                    'meta' => ['key' => 'workspaces'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Activities' => Http::response([
                    'activities' => [
                        ['sid' => self::AVAILABLE_SID, 'friendly_name' => 'Available'],
                        ['sid' => self::UNAVAILABLE_SID, 'friendly_name' => 'Unavailable'],
                    ],
                    'meta' => ['key' => 'activities'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/TaskQueues' && $method === 'GET' => Http::response([
                    'task_queues' => [['sid' => self::TASK_QUEUE_SID, 'friendly_name' => 'Everyone']],
                    'meta' => ['key' => 'task_queues'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Workflows' && $method === 'GET' => Http::response([
                    'workflows' => [['sid' => self::WORKFLOW_SID, 'friendly_name' => 'Voice Workflow']],
                    'meta' => ['key' => 'workflows'],
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Workflows/'.self::WORKFLOW_SID && $method === 'POST' => Http::response([
                    'sid' => self::WORKFLOW_SID, 'friendly_name' => 'Voice Workflow',
                ]),
                $path === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Workers' && $method === 'POST' => Http::response([
                    'sid' => 'WKNEWWORKER00000000000000000002', 'friendly_name' => 'Pending Agent',
                ]),
                str_ends_with($path, '/Channels/voice') => Http::response([
                    'sid' => 'WCTEST0000000000000000000000001',
                    'task_channel_unique_name' => 'voice',
                    'configured_capacity' => 1,
                ]),
                default => Http::response(['message' => "Unexpected fake request: {$method} {$path}"], 500),
            };
        });

        $result = app(TaskRouterProvisioner::class)->provision(self::APP_URL);

        $this->assertSame(self::WORKSPACE_SID, $result['workspaceSid']);
        $this->assertSame(self::TASK_QUEUE_SID, $result['taskQueueSid']);
        $this->assertSame(self::WORKFLOW_SID, $result['workflowSid']);
        $this->assertSame([
            ['agent_id' => $pending->id, 'worker_sid' => 'WKNEWWORKER00000000000000000002'],
        ], $result['workers']);

        $this->assertSame('WKEXISTING000000000000000000001', $alreadyProvisioned->fresh()->twilio_worker_sid);
        $this->assertSame('WKNEWWORKER00000000000000000002', $pending->fresh()->twilio_worker_sid);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/v1/Workspaces/'.self::WORKSPACE_SID.'/Workflows/'.self::WORKFLOW_SID);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/v1/Workspaces');
    }
}
