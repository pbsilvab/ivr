<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Voicemail;
use Twilio\Rest\Client;

class VoicemailHandler
{
    public function __construct(private Client $twilioClient)
    {
    }

    public function handleRecording(array $payload): void
    {
        $callSid = $payload['CallSid'];
        $recordingSid = $payload['RecordingSid'] ?? null;
        $recordingUrl = $payload['RecordingUrl'] ?? null;

        // Find the Call record
        $call = Call::where('call_sid', $callSid)->first();
        if (! $call) {
            return; // Call not found, silently return
        }

        // Create or update Voicemail record
        $voicemail = Voicemail::updateOrCreate(
            ['call_id' => $call->id],
            [
                'recording_sid' => $recordingSid,
                'recording_url' => $recordingUrl,
            ]
        );

        // Update Call status
        $call->update(['status' => 'voicemail_recorded', 'outcome' => 'voicemail']);

        // Idempotency: Only notify agents if SMS not already sent
        if (! $voicemail->sms_sid) {
            $this->notifyAgentsOfVoicemail($call, $voicemail);
        }
    }

    private function notifyAgentsOfVoicemail(Call $call, Voicemail $voicemail): void
    {
        // Get all agents with phone numbers
        $agents = \App\Models\Agent::whereNotNull('phone_number')->get();

        if ($agents->isEmpty()) {
            return; // No agents to notify
        }

        $message = "New voicemail from {$call->from_number}. Recording URL: {$voicemail->recording_url}";

        foreach ($agents as $agent) {
            try {
                $sms = $this->twilioClient->messages->create(
                    $agent->phone_number,
                    [
                        'from' => config('services.twilio.number'),
                        'body' => $message,
                    ]
                );

                // Save SMS SID to first agent's notification (idempotency marker)
                if (! $voicemail->sms_sid) {
                    $voicemail->update(['sms_sid' => $sms->sid]);
                }
            } catch (\Exception $e) {
                // Log SMS failure but continue
                \Log::warning("Failed to send voicemail SMS to {$agent->phone_number}: " . $e->getMessage());
            }
        }
    }
}

