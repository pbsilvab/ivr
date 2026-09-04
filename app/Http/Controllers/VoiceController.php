<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Services\RouteCallToAgentAction;
use App\Services\TaskTimeoutHandler;
use App\Services\VoicemailHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\TwiML\VoiceResponse;

class VoiceController extends Controller
{
    public function incoming(Request $request): Response
    {
        $callSid = $request->input('CallSid');
        $from = $request->input('From');

        // Idempotency: Check if Call already exists
        $call = Call::firstOrCreate(
            ['call_sid' => $callSid],
            [
                'from_number' => $from,
                'status' => 'initiated',
            ]
        );

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

    public function gatherDigits(Request $request, RouteCallToAgentAction $routeToAgent): Response
    {
        $callSid = $request->input('CallSid');
        $digit = $request->input('Digits');

        // Find the Call record
        $call = Call::where('call_sid', $callSid)->firstOrFail();

        // Idempotency: If already processed digit 1, don't create another task
        if ($digit === '1' && $call->task_sid) {
            $response = new VoiceResponse();
            $response->enqueue(null, [
                'workflowSid' => config('services.twilio.workflow_sid'),
                'taskAttributes' => json_encode([
                    'callSid' => $call->call_sid,
                    'from' => $call->from_number,
                ]),
            ]);
            return response($response, 200)
                ->header('Content-Type', 'application/xml');
        }

        $response = new VoiceResponse();

        if ($digit === '1') {
            // Route to agent
            $response = $routeToAgent->handle($call);
        } elseif ($digit === '2') {
            // Voicemail route
            $response->say('Please record your message after the beep. Press any key when finished.');
            $response->record([
                'action' => url('/api/voice/voicemail-record'),
                'method' => 'POST',
            ]);
        } else {
            // Invalid input
            $response->say('Sorry, I did not recognize that input.');
            $response->redirect(url('/api/voice/incoming'));
        }

        return response($response, 200)
            ->header('Content-Type', 'application/xml');
    }

    public function voicemailRecord(Request $request, VoicemailHandler $voicemailHandler): Response
    {
        $payload = $request->all();
        $voicemailHandler->handleRecording($payload);

        // Return minimal TwiML - call ends here
        $response = new VoiceResponse();
        $response->say('Thank you for your message. Goodbye.');
        $response->hangup();

        return response($response, 200)
            ->header('Content-Type', 'application/xml');
    }

    public function noAgentAvailable(Request $request, TaskTimeoutHandler $timeoutHandler, VoicemailHandler $voicemailHandler): Response
    {
        $callSid = $request->input('CallSid');

        // Find the Call record
        $call = Call::where('call_sid', $callSid)->first();

        $response = new VoiceResponse();

        if ($call && ! $timeoutHandler->wasTaskAccepted($callSid)) {
            // No agent accepted - fallback to voicemail
            $response->say('All agents are currently unavailable. Please leave a message and we will get back to you shortly.');
            $response->record([
                'action' => url('/api/voice/voicemail-record'),
                'method' => 'POST',
            ]);
        } else {
            // Should not reach here if an agent accepted
            $response->say('An error occurred. Please try again later.');
            $response->hangup();
        }

        return response($response, 200)
            ->header('Content-Type', 'application/xml');
    }
}



