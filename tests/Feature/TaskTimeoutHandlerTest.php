<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\TaskRecord;
use App\Services\TaskTimeoutHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskTimeoutHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): TaskTimeoutHandler
    {
        return app(TaskTimeoutHandler::class);
    }

    private function fakeCallRedirect(): void
    {
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Calls/*' => Http::response(['sid' => 'CAfake'], 200),
        ]);
    }

    public function test_task_created_records_the_task_against_its_call(): void
    {
        $call = Call::create([
            'call_sid' => 'CAcreated001',
            'from_number' => '+15551234567',
            'status' => 'queued',
        ]);

        $this->handler()->handleTaskEvent([
            'EventType' => 'task.created',
            'TaskSid' => 'WTcreated001',
            'TaskAttributes' => json_encode(['callSid' => 'CAcreated001', 'from' => '+15551234567']),
        ]);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTcreated001',
            'call_id' => $call->id,
            'status' => 'pending',
        ]);
        $this->assertSame('WTcreated001', $call->fresh()->task_sid);
    }

    public function test_a_repeated_task_created_event_records_one_task(): void
    {
        $call = Call::create([
            'call_sid' => 'CAcreateddup001',
            'from_number' => '+15551234567',
            'status' => 'queued',
        ]);

        $payload = [
            'EventType' => 'task.created',
            'TaskSid' => 'WTcreateddup001',
            'TaskAttributes' => json_encode(['callSid' => 'CAcreateddup001']),
        ];

        $this->handler()->handleTaskEvent($payload);
        $this->handler()->handleTaskEvent($payload);

        $this->assertSame(1, TaskRecord::where('call_id', $call->id)->count());
    }

    /**
     * Twilio also creates Tasks we did not enqueue; without our callSid there is nothing to tie
     * one to a local Call.
     */
    public function test_task_created_ignores_a_task_without_our_call_sid(): void
    {
        $this->handler()->handleTaskEvent([
            'EventType' => 'task.created',
            'TaskSid' => 'WTforeign001',
            'TaskAttributes' => json_encode(['from_country' => 'US', 'called_zip' => '65646']),
        ]);

        $this->assertSame(0, TaskRecord::count());
    }

    public function test_task_canceled_redirects_the_waiting_call_to_voicemail(): void
    {
        $this->fakeCallRedirect();

        $call = Call::create([
            'call_sid' => 'CAcanceled001',
            'from_number' => '+15551234567',
            'status' => 'queued',
            'task_sid' => 'WTcanceled001',
        ]);

        TaskRecord::create([
            'task_sid' => 'WTcanceled001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $this->handler()->handleTaskEvent([
            'EventType' => 'task.canceled',
            'TaskSid' => 'WTcanceled001',
        ]);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTcanceled001',
            'status' => 'timeout',
        ]);
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAcanceled001',
            'status' => 'agent_unavailable',
            'outcome' => 'no_agent',
        ]);

        // TaskRouter only cancels the Task; without this the caller stays in <Enqueue> forever.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/Calls/CAcanceled001.json')
            && $request['Url'] === url('/api/voice/no-agent-available'));
    }

    public function test_task_canceled_leaves_an_already_accepted_task_alone(): void
    {
        $this->fakeCallRedirect();

        $call = Call::create([
            'call_sid' => 'CAaccepted001',
            'from_number' => '+15551234567',
            'status' => 'accepted',
            'task_sid' => 'WTaccepted001',
        ]);

        TaskRecord::create([
            'task_sid' => 'WTaccepted001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'accepted',
            'reservation_sid' => 'WRaccepted001',
        ]);

        $this->handler()->handleTaskEvent([
            'EventType' => 'task.canceled',
            'TaskSid' => 'WTaccepted001',
        ]);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTaccepted001',
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAaccepted001',
            'status' => 'accepted',
        ]);

        Http::assertNothingSent();
    }

    public function test_a_repeated_task_canceled_event_redirects_once(): void
    {
        $this->fakeCallRedirect();

        $call = Call::create([
            'call_sid' => 'CAcanceleddup001',
            'from_number' => '+15551234567',
            'status' => 'queued',
            'task_sid' => 'WTcanceleddup001',
        ]);

        TaskRecord::create([
            'task_sid' => 'WTcanceleddup001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $payload = ['EventType' => 'task.canceled', 'TaskSid' => 'WTcanceleddup001'];

        $this->handler()->handleTaskEvent($payload);
        $this->handler()->handleTaskEvent($payload);
        $this->handler()->handleTaskEvent($payload);

        Http::assertSentCount(1);
    }

    public function test_task_wrapup_completes_the_task_so_the_worker_regains_capacity(): void
    {
        Http::fake(['https://taskrouter.twilio.com/*' => Http::response(['sid' => 'WTwrapup001'], 200)]);

        $call = Call::create([
            'call_sid' => 'CAwrapup001',
            'from_number' => '+15551234567',
            'status' => 'accepted',
            'task_sid' => 'WTwrapup001',
        ]);

        TaskRecord::create([
            'task_sid' => 'WTwrapup001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'accepted',
            'reservation_sid' => 'WRwrapup001',
        ]);

        $payload = ['EventType' => 'task.wrapup', 'TaskSid' => 'WTwrapup001'];

        $this->handler()->handleTaskEvent($payload);
        $this->handler()->handleTaskEvent($payload);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTwrapup001',
            'status' => 'completed',
        ]);

        // A wrapping Task holds the Worker's channel capacity until it is completed.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/Tasks/WTwrapup001')
            && $request['AssignmentStatus'] === 'completed');
    }

    public function test_it_ignores_events_that_carry_no_task_sid(): void
    {
        $this->handler()->handleTaskEvent([
            'EventType' => 'worker.activity.update',
            'WorkerSid' => 'WKworker001',
            'WorkerActivityName' => 'Available',
        ]);

        $this->assertSame(0, TaskRecord::count());
    }

    public function test_was_task_accepted_true_with_reservation(): void
    {
        $call = Call::create([
            'call_sid' => 'CAwasaccepted001',
            'from_number' => '+15554444444',
            'status' => 'accepted',
            'task_sid' => 'WTwasaccepted001',
        ]);

        TaskRecord::create([
            'task_sid' => 'WTwasaccepted001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'accepted',
            'reservation_sid' => 'WRwasaccepted001',
        ]);

        $this->assertTrue($this->handler()->wasTaskAccepted('CAwasaccepted001'));
    }

    public function test_was_task_accepted_false_without_reservation(): void
    {
        Call::create([
            'call_sid' => 'CAwasnotaccepted001',
            'from_number' => '+15555555555',
            'status' => 'initiated',
        ]);

        $this->assertFalse($this->handler()->wasTaskAccepted('CAwasnotaccepted001'));
    }

    public function test_was_task_accepted_false_for_unknown_call(): void
    {
        $this->assertFalse($this->handler()->wasTaskAccepted('CAunknown001'));
    }
}
