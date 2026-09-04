<?php

namespace App\Services;

use App\Models\Call;
use App\Models\TaskRecord;
use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;

class RouteCallToAgentAction
{
    public function __construct(private Client $twilioClient)
    {
    }

    public function handle(Call $call): VoiceResponse
    {
        $workspaceSid = config('services.twilio.workspace_sid');
        $workflowSid = config('services.twilio.workflow_sid');

        // Create a Task in Twilio TaskRouter
        $task = $this->twilioClient->taskrouter->v1->workspaces($workspaceSid)
            ->tasks->create([
                'workflowSid' => $workflowSid,
                'attributes' => json_encode([
                    'callSid' => $call->call_sid,
                    'from' => $call->from_number,
                ]),
            ]);

        // Save task record
        $taskSid = $task->sid;
        $call->update(['task_sid' => $taskSid]);

        TaskRecord::create([
            'task_sid' => $taskSid,
            'call_id' => $call->id,
            'workflow_sid' => $workflowSid,
            'status' => 'pending',
        ]);

        // Build TwiML response with Enqueue
        $response = new VoiceResponse();
        $enqueue = $response->enqueue(null, [
            'workflowSid' => $workflowSid,
            'taskAttributes' => json_encode([
                'callSid' => $call->call_sid,
                'from' => $call->from_number,
            ]),
        ]);

        return $response;
    }
}

