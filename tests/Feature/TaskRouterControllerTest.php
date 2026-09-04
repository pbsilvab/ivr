<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Call;
use App\Models\TaskRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskRouterControllerTest extends TestCase
{
    use RefreshDatabase;

    private const AVAILABLE_SID = 'WAavailable0000000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.number' => '+15550001111',
            'services.twilio.activity_available_sid' => self::AVAILABLE_SID,
        ]);
    }

    private function pendingTask(string $taskSid, string $callSid): TaskRecord
    {
        $call = Call::create([
            'call_sid' => $callSid,
            'from_number' => '+15559999999',
            'status' => 'queued',
            'task_sid' => $taskSid,
        ]);

        return TaskRecord::create([
            'task_sid' => $taskSid,
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);
    }

    public function test_assignment_returns_a_dequeue_instruction_for_the_reserved_agent(): void
    {
        $agent = Agent::create([
            'name' => 'test_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKtest001',
        ]);

        $this->pendingTask('WTassign001', 'CAassign001');

        $response = $this->twilioPost('/api/taskrouter/assignment', [
            'TaskSid' => 'WTassign001',
            'WorkerSid' => 'WKtest001',
            'ReservationSid' => 'WRassign001',
        ]);

        $response->assertStatus(200);
        $response->assertExactJson([
            'instruction' => 'dequeue',
            'to' => '+15551234567',
            'from' => '+15550001111',
            'post_work_activity_sid' => self::AVAILABLE_SID,
        ]);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTassign001',
            'status' => 'accepted',
            'reservation_sid' => 'WRassign001',
        ]);
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAassign001',
            'status' => 'accepted',
            'outcome' => 'routed',
            'agent_id' => $agent->id,
        ]);
    }

    public function test_assignment_instructs_nothing_for_a_worker_we_do_not_know(): void
    {
        $this->pendingTask('WTunknownworker001', 'CAunknownworker001');

        $response = $this->twilioPost('/api/taskrouter/assignment', [
            'TaskSid' => 'WTunknownworker001',
            'WorkerSid' => 'WKnotours001',
            'ReservationSid' => 'WRunknownworker001',
        ]);

        $response->assertStatus(200);
        $this->assertSame('', $response->getContent());

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTunknownworker001',
            'status' => 'pending',
        ]);
    }

    public function test_assignment_instructs_nothing_for_a_task_we_do_not_know(): void
    {
        Agent::create([
            'name' => 'orphan_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKorphan001',
        ]);

        $response = $this->twilioPost('/api/taskrouter/assignment', [
            'TaskSid' => 'WTnotours001',
            'WorkerSid' => 'WKorphan001',
        ]);

        $response->assertStatus(200);
        $this->assertSame('', $response->getContent());
    }

    /**
     * Out-of-order delivery: the caller is already on their way to voicemail, so a late
     * reservation must not pull them back out of it.
     */
    public function test_assignment_instructs_nothing_once_the_task_has_timed_out(): void
    {
        Agent::create([
            'name' => 'late_agent',
            'phone_number' => '+15551234567',
            'twilio_worker_sid' => 'WKlate001',
        ]);

        $taskRecord = $this->pendingTask('WTlate001', 'CAlate001');
        $taskRecord->update(['status' => 'timeout']);

        $response = $this->twilioPost('/api/taskrouter/assignment', [
            'TaskSid' => 'WTlate001',
            'WorkerSid' => 'WKlate001',
            'ReservationSid' => 'WRlate001',
        ]);

        $response->assertStatus(200);
        $this->assertSame('', $response->getContent());

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTlate001',
            'status' => 'timeout',
        ]);
    }

    public function test_assignment_instructs_nothing_without_a_task_sid(): void
    {
        $response = $this->twilioPost('/api/taskrouter/assignment', ['WorkerSid' => 'WKnostatus001']);

        $response->assertStatus(200);
        $this->assertSame('', $response->getContent());
    }

    public function test_events_task_created_records_the_task(): void
    {
        $call = Call::create([
            'call_sid' => 'CAeventcreated001',
            'from_number' => '+15559999999',
            'status' => 'queued',
        ]);

        $response = $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'task.created',
            'TaskSid' => 'WTeventcreated001',
            'TaskAttributes' => json_encode(['callSid' => 'CAeventcreated001']),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed']);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTeventcreated001',
            'call_id' => $call->id,
            'status' => 'pending',
        ]);
    }

    public function test_events_task_canceled_falls_back_to_voicemail(): void
    {
        Http::fake(['https://api.twilio.com/2010-04-01/Accounts/*/Calls/*' => Http::response(['sid' => 'CAfake'], 200)]);

        $this->pendingTask('WTeventcanceled001', 'CAeventcanceled001');

        $response = $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'task.canceled',
            'TaskSid' => 'WTeventcanceled001',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTeventcanceled001',
            'status' => 'timeout',
        ]);
        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAeventcanceled001',
            'status' => 'agent_unavailable',
            'outcome' => 'no_agent',
        ]);
    }

    /**
     * The event callback is registered on the Workspace, so TaskRouter posts every event in it —
     * most of which are not Task-scoped at all.
     */
    public function test_events_ignores_a_worker_activity_event(): void
    {
        $response = $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'worker.activity.update',
            'WorkerSid' => 'WKworkeractivity001',
            'WorkerActivityName' => 'Available',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed']);
    }

    public function test_events_leaves_a_task_alone_on_an_event_it_does_not_act_on(): void
    {
        $this->pendingTask('WTreservationevent001', 'CAreservationevent001');

        $response = $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'reservation.created',
            'TaskSid' => 'WTreservationevent001',
            'ReservationSid' => 'WRreservationevent001',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTreservationevent001',
            'status' => 'pending',
        ]);
    }

    public function test_events_ignores_unknown_task(): void
    {
        $response = $this->twilioPost('/api/taskrouter/events', [
            'EventType' => 'task.canceled',
            'TaskSid' => 'WTunknownevent001',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed']);
    }
}
