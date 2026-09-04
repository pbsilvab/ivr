<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $url = $request->url();
        $authToken = config('services.twilio.token');

        if (! $this->isValidSignature($signature, $url, $request->all(), $authToken)) {
            return response('Unauthorized', 403);
        }

        return $next($request);
    }

    private function isValidSignature(string $signature = null, string $url, array $params, string $authToken): bool
    {
        if (! $signature) {
            return false;
        }

        // Reconstruct the URL as Twilio does: url + sorted params (by key order as received)
        $data = $url;
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = implode('', $value);
            }
            $data .= $key . $value;
        }

        // Compute HMAC-SHA1
        $computedHash = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($computedHash, $signature);
    }
}
