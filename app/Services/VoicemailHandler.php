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
        Voicemail::updateOrCreate(
            ['call_id' => $call->id],
            [
                'recording_sid' => $recordingSid,
                'recording_url' => $recordingUrl,
            ]
        );

        // Update Call status
        $call->update(['status' => 'voicemail_recorded', 'outcome' => 'voicemail']);

        // Notify agents via SMS (if any agents available)
        $this->notifyAgentsOfVoicemail($call);
    }

    private function notifyAgentsOfVoicemail(Call $call): void
    {
        // Get all agents with phone numbers
        $agents = \App\Models\Agent::whereNotNull('phone_number')->get();

        if ($agents->isEmpty()) {
            return; // No agents to notify
        }

        $message = "New voicemail from {$call->from_number}. Recording URL: {$call->voicemail?->recording_url}";

        foreach ($agents as $agent) {
            try {
                $sms = $this->twilioClient->messages->create(
                    $agent->phone_number,
                    [
                        'from' => config('services.twilio.number'),
                        'body' => $message,
                    ]
                );

                // Save SMS SID if this is the first voicemail SMS
                if ($call->voicemail && ! $call->voicemail->sms_sid) {
                    $call->voicemail->update(['sms_sid' => $sms->sid]);
                }
            } catch (\Exception $e) {
                // Log SMS failure but continue
                \Log::warning("Failed to send voicemail SMS to {$agent->phone_number}: " . $e->getMessage());
            }
        }
    }
}

