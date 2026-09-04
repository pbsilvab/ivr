<?php

namespace App\Services;

use App\Models\Call;
use App\Models\TaskRecord;
use Twilio\Rest\Client;

class TaskTimeoutHandler
{
    public function __construct(private Client $twilioClient) {}

    /**
     * Workspace event callback. It is registered on the Workspace, so TaskRouter posts *every*
     * event in it — worker.activity.update, reservation.created, task-queue.entered and so on.
     * Only two of them are ours.
     */
    public function handleTaskEvent(array $payload): void
    {
        $taskSid = $payload['TaskSid'] ?? null;

        if ($taskSid === null) {
            return;
        }

        match ($payload['EventType'] ?? null) {
            'task.created' => $this->recordTask($taskSid, $payload),
            'task.canceled' => $this->fallBackToVoicemail($taskSid),
            default => null,
        };
    }

    public function wasTaskAccepted(string $callSid): bool
    {
        $call = Call::where('call_sid', $callSid)->first();

        if (! $call || ! $call->task_sid) {
            return false;
        }

        $taskRecord = TaskRecord::where('task_sid', $call->task_sid)->first();

        return $taskRecord !== null && ! empty($taskRecord->reservation_sid);
    }

    /**
     * <Enqueue> creates the Task, so this event is the first time we learn its SID. The callSid
     * we put in the Task attributes is what ties it back to the local Call.
     */
    private function recordTask(string $taskSid, array $payload): void
    {
        $attributes = json_decode($payload['TaskAttributes'] ?? '{}', true);
        $callSid = is_array($attributes) ? ($attributes['callSid'] ?? null) : null;

        if ($callSid === null) {
            return;
        }

        $call = Call::where('call_sid', $callSid)->first();

        if (! $call) {
            return;
        }

        TaskRecord::firstOrCreate(
            ['task_sid' => $taskSid],
            [
                'call_id' => $call->id,
                'workflow_sid' => config('services.twilio.workflow_sid'),
                'status' => 'pending',
            ],
        );

        $call->update(['task_sid' => $taskSid]);
    }

    /**
     * The Workflow timeout cancels the Task, but TaskRouter has no say over the call itself —
     * the caller is still sitting in <Enqueue>. Redirecting the live call is what actually gets
     * them to voicemail instead of waiting indefinitely.
     */
    private function fallBackToVoicemail(string $taskSid): void
    {
        $taskRecord = TaskRecord::where('task_sid', $taskSid)->first();

        if (! $taskRecord || $taskRecord->status !== 'pending' || $taskRecord->reservation_sid) {
            return;
        }

        $taskRecord->update(['status' => 'timeout']);

        $call = $taskRecord->call;
        $call->update(['status' => 'agent_unavailable', 'outcome' => 'no_agent']);

        $this->twilioClient->calls($call->call_sid)->update([
            'url' => url('/api/voice/no-agent-available'),
            'method' => 'POST',
        ]);
    }
}
