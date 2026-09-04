<?php

namespace App\Services;

use App\Models\Call;
use Twilio\TwiML\VoiceResponse;

class RouteCallToAgentAction
{
    /**
     * Put the caller into the Workflow's queue.
     *
     * The Task is created by <Enqueue>, not by the REST API: the caller has to actually be *in*
     * the queue for the assignment callback's `dequeue` instruction to have someone to bridge.
     * A Task created over REST holds no call leg, so it can never connect anyone.
     *
     * Its SID only exists once Twilio builds it, so the local TaskRecord is written when the
     * `task.created` event arrives — see {@see TaskTimeoutHandler}.
     */
    public function handle(Call $call): VoiceResponse
    {
        $response = new VoiceResponse;

        $enqueue = $response->enqueue(null, [
            'workflowSid' => config('services.twilio.workflow_sid'),
        ]);

        // Attributes travel in the <Task> noun; <Enqueue> has no taskAttributes attribute, so
        // passing one there is silently dropped and the Task arrives without our callSid.
        $enqueue->task(json_encode([
            'callSid' => $call->call_sid,
            'from' => $call->from_number,
        ]));

        $call->update(['status' => 'queued']);

        return $response;
    }
}
