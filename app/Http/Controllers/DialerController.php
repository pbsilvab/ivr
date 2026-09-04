<?php

namespace App\Http\Controllers;

use App\Services\Dialer\DialerOutboundAction;
use App\Services\Dialer\DialerTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

class DialerController extends Controller
{
    /**
     * The softphone UI. Plays the role of an external customer calling the Twilio number.
     */
    public function show(): View
    {
        $configured = (string) config('services.twilio.twiml_app_sid') !== ''
            && (string) config('services.twilio.api_key_sid') !== ''
            && (string) config('services.twilio.api_key_secret') !== '';

        return view('dialer', [
            'destination' => (string) config('services.twilio.number'),
            'callerId' => (string) config('services.twilio.dialer.caller_id'),
            'configured' => $configured,
            'dialerConfig' => [
                // Relative on purpose: the fetch is same-origin, so it inherits the page's scheme
                // and can never end up as an http:// request from an https:// page.
                'tokenUrl' => '/api/dialer/token',
                'destination' => (string) config('services.twilio.number'),
                'configured' => $configured,
            ],
        ]);
    }

    public function token(Request $request, DialerTokenIssuer $issuer): JsonResponse
    {
        $validated = $request->validate([
            'identity' => 'nullable|string|max:40',
        ]);

        try {
            return response()->json($issuer->issue($validated['identity'] ?? null), 200);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /**
     * Voice webhook of the dialer's TwiML Application — Twilio calls this when the browser
     * places a call.
     */
    public function outbound(Request $request, DialerOutboundAction $action): Response
    {
        $response = $action->handle($request->input('To'));

        return response($response, 200)
            ->header('Content-Type', 'application/xml');
    }
}
