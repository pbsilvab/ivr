<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\TaskRecord;

class TaskAssignmentHandler
{
    /**
     * Answer TaskRouter's assignment callback with an assignment instruction.
     *
     * TaskRouter expects JSON instructions here (`dequeue`, `call`, `redirect`, `conference`) —
     * not TwiML. `dequeue` takes the caller waiting in <Enqueue> and bridges them to the agent.
     * An empty response leaves the reservation alone, which is what we want whenever we cannot
     * safely connect anyone.
     *
     * @return array<string, string>
     */
    public function handleAssignmentCallback(array $payload): array
    {
        $taskSid = $payload['TaskSid'] ?? null;
        $workerSid = $payload['WorkerSid'] ?? null;

        if ($taskSid === null || $workerSid === null) {
            return [];
        }

        $taskRecord = TaskRecord::where('task_sid', $taskSid)->first();
        $agent = Agent::where('twilio_worker_sid', $workerSid)->first();

        if (! $taskRecord || ! $agent) {
            return [];
        }

        // Out-of-order safety: once a Task has timed out the caller is already on their way to
        // voicemail, so a late reservation must not pull them back out.
        if ($taskRecord->status !== 'pending') {
            return [];
        }

        $taskRecord->update([
            'status' => 'accepted',
            'reservation_sid' => $payload['ReservationSid'] ?? null,
        ]);

        $taskRecord->call->update([
            'status' => 'accepted',
            'outcome' => 'routed',
            'agent_id' => $agent->id,
        ]);

        return [
            'instruction' => 'dequeue',
            'to' => $agent->phone_number,
            'from' => config('services.twilio.number'),
            // Where the Worker lands after hanging up. Available keeps them taking calls; switch
            // to activity_unavailable_sid for a wrap-up-then-opt-back-in flow.
            'post_work_activity_sid' => config('services.twilio.activity_available_sid'),
        ];
    }
}
