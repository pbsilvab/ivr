<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\TaskRecord;
use App\Services\TaskTimeoutHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTimeoutHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_handles_task_timeout_without_reservation(): void
    {
        $handler = new TaskTimeoutHandler();

        $call = Call::create([
            'call_sid' => 'CAtimeouttest001',
            'from_number' => '+15551234567',
            'status' => 'initiated',
            'task_sid' => 'WTtimeouttest001',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTtimeouttest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $payload = [
            'TaskSid' => 'WTtimeouttest001',
            'TaskStatus' => 'wrapup',
            'EventType' => 'task.completed',
        ];

        $handler->handleTaskEvent($payload);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTtimeouttest001',
            'status' => 'timeout',
            'reservation_sid' => null,
        ]);

        $this->assertDatabaseHas('calls', [
            'call_sid' => 'CAtimeouttest001',
            'status' => 'agent_unavailable',
            'outcome' => 'no_agent',
        ]);
    }

    public function test_handles_task_completed_without_reservation(): void
    {
        $handler = new TaskTimeoutHandler();

        $call = Call::create([
            'call_sid' => 'CAcompletedtest001',
            'from_number' => '+15552222222',
            'status' => 'initiated',
            'task_sid' => 'WTcompletedtest001',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTcompletedtest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'pending',
        ]);

        $payload = [
            'TaskSid' => 'WTcompletedtest001',
            'TaskStatus' => 'completed',
        ];

        $handler->handleTaskEvent($payload);

        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTcompletedtest001',
            'status' => 'timeout',
        ]);
    }

    public function test_ignores_task_with_reservation(): void
    {
        $handler = new TaskTimeoutHandler();

        $call = Call::create([
            'call_sid' => 'CAreservedtest001',
            'from_number' => '+15553333333',
            'status' => 'accepted',
            'task_sid' => 'WTreservedtest001',
        ]);

        $taskRecord = TaskRecord::create([
            'task_sid' => 'WTreservedtest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'accepted',
            'reservation_sid' => 'WRreservedtest001',
        ]);

        $payload = [
            'TaskSid' => 'WTreservedtest001',
            'TaskStatus' => 'completed',
        ];

        $handler->handleTaskEvent($payload);

        // Should not change status since it has a reservation
        $this->assertDatabaseHas('task_records', [
            'task_sid' => 'WTreservedtest001',
            'status' => 'accepted', // unchanged
            'reservation_sid' => 'WRreservedtest001',
        ]);
    }

    public function test_was_task_accepted_true_with_reservation(): void
    {
        $handler = new TaskTimeoutHandler();

        $call = Call::create([
            'call_sid' => 'CAwasacceptedtest001',
            'from_number' => '+15554444444',
            'status' => 'accepted',
            'task_sid' => 'WTwasacceptedtest001',
        ]);

        TaskRecord::create([
            'task_sid' => 'WTwasacceptedtest001',
            'call_id' => $call->id,
            'workflow_sid' => config('services.twilio.workflow_sid'),
            'status' => 'accepted',
            'reservation_sid' => 'WRwasacceptedtest001',
        ]);

        $this->assertTrue($handler->wasTaskAccepted('CAwasacceptedtest001'));
    }

    public function test_was_task_accepted_false_without_reservation(): void
    {
        $handler = new TaskTimeoutHandler();

        $call = Call::create([
            'call_sid' => 'CAwasnotacceptedtest001',
            'from_number' => '+15555555555',
            'status' => 'initiated',
        ]);

        $this->assertFalse($handler->wasTaskAccepted('CAwasnotacceptedtest001'));
    }

    public function test_was_task_accepted_false_for_unknown_call(): void
    {
        $handler = new TaskTimeoutHandler();

        $this->assertFalse($handler->wasTaskAccepted('CAunknowntest001'));
    }
}

