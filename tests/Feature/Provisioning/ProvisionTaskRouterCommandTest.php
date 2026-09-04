<?php

namespace Tests\Feature\Provisioning;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionTaskRouterCommandTest extends TestCase
{
    use RefreshDatabase;

    private const WORKSPACE_SID = 'WSTEST0000000000000000000000001';

    private const AVAILABLE_SID = 'WATESTAVAILABLE0000000000000001';

    private const UNAVAILABLE_SID = 'WATESTUNAVAILABLE000000000000001';

    private const TASK_QUEUE_SID = 'WQTEST0000000000000000000000001';

    private const WORKFLOW_SID = 'WWTEST0000000000000000000000001';

    private function fakeExistingWorkspace(): void
    {
        Http::fake(function (Request $request) {
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
                default => Http::response(['sid' => self::WORKFLOW_SID], 200),
            };
        });
    }

    /**
     * The SIDs are not persisted anywhere: the app reads them from `config('services.twilio.*')`,
     * so printing them for `.env` is the entire handover.
     */
    public function test_it_prints_every_sid_for_the_env_file(): void
    {
        $this->fakeExistingWorkspace();

        Agent::create([
            'name' => 'Already Provisioned',
            'phone_number' => '+15550001111',
            'twilio_worker_sid' => 'WKEXISTING000000000000000000001',
        ]);

        $this->artisan('taskrouter:provision')
            ->expectsOutputToContain('Add to .env:')
            ->expectsOutputToContain('TWILIO_WORKSPACE_SID='.self::WORKSPACE_SID)
            ->expectsOutputToContain('TWILIO_TASKQUEUE_SID='.self::TASK_QUEUE_SID)
            ->expectsOutputToContain('TWILIO_WORKFLOW_SID='.self::WORKFLOW_SID)
            ->expectsOutputToContain('TWILIO_ACTIVITY_AVAILABLE_SID='.self::AVAILABLE_SID)
            ->expectsOutputToContain('TWILIO_ACTIVITY_UNAVAILABLE_SID='.self::UNAVAILABLE_SID)
            ->assertSuccessful();
    }

    public function test_it_no_longer_writes_the_sids_to_the_settings_table(): void
    {
        $this->fakeExistingWorkspace();

        Agent::create([
            'name' => 'Already Provisioned',
            'phone_number' => '+15550002222',
            'twilio_worker_sid' => 'WKEXISTING000000000000000000002',
        ]);

        $this->artisan('taskrouter:provision')->assertSuccessful();

        // config() is the single source of truth; a second copy in the database was only ever
        // written, never read.
        $this->assertDatabaseCount('settings', 0);
    }
}
