<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twilio\Security\RequestValidator;

class ValidateTwilioSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Twilio-Signature');

        if (! $signature) {
            return response('Unauthorized', 403);
        }

        // The SDK's validator is the authority on the scheme: parameters are concatenated sorted
        // by key (Twilio does not post them in alphabetical order), and the signature is checked
        // both with and without the port, which Twilio itself is inconsistent about.
        $validator = new RequestValidator((string) config('services.twilio.token'));

        if (! $validator->validate($signature, $request->url(), $request->all())) {
            return response('Unauthorized', 403);
        }

        return $next($request);
    }
}
