<?php

namespace App\Services;

use App\Models\Call;
use App\Models\TaskRecord;

class TaskTimeoutHandler
{
    public function handleTaskEvent(array $payload): void
    {
        $taskSid = $payload['TaskSid'];
        $taskStatus = $payload['TaskStatus'];
        $eventType = $payload['EventType'] ?? null;

        $taskRecord = TaskRecord::where('task_sid', $taskSid)->first();
        if (! $taskRecord) {
            return; // Task not found
        }

        $call = $taskRecord->call;

        // Idempotency: Only update if task is still in pending state and being marked as timeout
        if (in_array($taskStatus, ['completed', 'wrapup']) && 
            ! $taskRecord->reservation_sid && 
            $taskRecord->status === 'pending') {
            
            $taskRecord->update(['status' => 'timeout']);
            $call->update(['status' => 'agent_unavailable', 'outcome' => 'no_agent']);
        }
    }

    public function wasTaskAccepted(string $callSid): bool
    {
        $call = Call::where('call_sid', $callSid)->first();
        if (! $call || ! $call->task_sid) {
            return false;
        }

        $taskRecord = TaskRecord::where('task_sid', $call->task_sid)->first();
        if (! $taskRecord) {
            return false;
        }

        return ! empty($taskRecord->reservation_sid);
    }
}

