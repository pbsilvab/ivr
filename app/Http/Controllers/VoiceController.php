<?php

namespace App\Http\Controllers;

use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\TwiML\VoiceResponse;

class VoiceController extends Controller
{
    public function incoming(Request $request): Response
    {
        $callSid = $request->input('CallSid');
        $from = $request->input('From');

        // Create Call record
        $call = Call::create([
            'call_sid' => $callSid,
            'from_number' => $from,
            'status' => 'initiated',
        ]);

        // Build TwiML response with Gather for IVR
        $response = new VoiceResponse();
        $gather = $response->gather([
            'numDigits' => 1,
            'action' => url('/api/voice/gather-digits'),
            'method' => 'POST',
        ]);

        $gather->say('Press 1 to reach an agent, or press 2 to leave a voicemail.');

        // Fallback if no input
        $response->redirect(url('/api/voice/incoming'));

        return response($response, 200)
            ->header('Content-Type', 'application/xml');
    }
}

