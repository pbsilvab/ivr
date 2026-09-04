<?php

namespace App\Services\Dialer;

use Twilio\TwiML\VoiceResponse;

/**
 * Voice URL of the dialer's TwiML Application: turns "the browser wants to call X" into the
 * TwiML that actually bridges the browser leg to the PSTN number.
 *
 * Dialing the app's own Twilio number means the call arrives at /api/voice/incoming exactly as
 * a real customer's would — same webhook, same IVR, same TaskRouter Task.
 */
class DialerOutboundAction
{
    public function handle(?string $to): VoiceResponse
    {
        $to = trim((string) $to);
        $response = new VoiceResponse;

        if (! $this->isAllowed($to)) {
            $response->say('That destination is not allowed from this dialer.');
            $response->hangup();

            return $response;
        }

        // answerOnBridge keeps the browser leg unanswered until the far end picks up, so the
        // softphone plays real ringback instead of silence.
        $dial = $response->dial('', [
            'callerId' => config('services.twilio.dialer.caller_id'),
            'answerOnBridge' => true,
        ]);

        $dial->number($to);

        return $response;
    }

    private function isAllowed(string $to): bool
    {
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $to)) {
            return false;
        }

        return in_array($to, (array) config('services.twilio.dialer.allowed_numbers', []), true);
    }
}
