<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Call;
use App\Models\TaskRecord;
use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;

class TaskAssignmentHandler
{
    public function __construct(private Client $twilioClient)
    {
    }

    public function handleAssignmentCallback(array $payload): VoiceResponse
    {
        $taskSid = $payload['TaskSid'];
        $workerSid = $payload['WorkerSid'];
        $assignmentStatus = $payload['AssignmentStatus'];

        $taskRecord = TaskRecord::where('task_sid', $taskSid)->firstOrFail();
        $call = $taskRecord->call;
        $agent = Agent::where('twilio_worker_sid', $workerSid)->first();

        $response = new VoiceResponse();

        // Idempotency: If task already in terminal state (timeout, rejected), don't update
        if (in_array($taskRecord->status, ['timeout', 'rejected'])) {
            // If assignment is accepted but task already timed out, return generic message
            if ($assignmentStatus === 'accepted') {
                $response->say('This call has already been handled.');
                $response->hangup();
            } else {
                $response->say('The agent is unavailable. Your call will be transferred to the next available agent.');
            }
            return $response;
        }

        // Idempotency: If task already marked as accepted, return dial response only
        if ($taskRecord->status === 'accepted' && $assignmentStatus === 'accepted') {
            if ($agent) {
                $response->dial($agent->phone_number, [
                    'callerId' => config('services.twilio.number'),
                ]);
            } else {
                $response->say('Unable to connect to an agent at this time.');
                $response->hangup();
            }
            return $response;
        }

        if ($assignmentStatus === 'accepted') {
            // Update task and call status
            $taskRecord->update(['status' => 'accepted', 'reservation_sid' => $payload['ReservationSid'] ?? null]);
            $call->update(['status' => 'accepted', 'agent_id' => $agent?->id]);

            // Build dial instruction to agent phone number
            if ($agent) {
                $response->dial($agent->phone_number, [
                    'callerId' => config('services.twilio.number'),
                ]);
            } else {
                // Worker not found in local system, hang up
                $response->say('Unable to connect to an agent at this time.');
                $response->hangup();
            }
        } elseif ($assignmentStatus === 'rejected' || $assignmentStatus === 'timeout') {
            // Idempotency: Only update if not already rejected
            if ($taskRecord->status !== 'rejected') {
                $taskRecord->update(['status' => 'rejected']);
            }
            $response->say('The agent is unavailable. Your call will be transferred to the next available agent.');
        } else {
            // Handle other statuses if needed
            $response->say('An unexpected error occurred.');
            $response->hangup();
        }

        return $response;
    }
}

