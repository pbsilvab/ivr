<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Twilio\AuthStrategy\AuthStrategy;
use Twilio\Http\Client as TwilioHttpClient;
use Twilio\Http\Response as TwilioResponse;

// Routes the twilio/sdk's requests through Laravel's HTTP client so Http::fake() can intercept them in tests.
class LaravelHttpTwilioClient implements TwilioHttpClient
{
    public function request(
        string $method,
        string $url,
        array $params = [],
        array $data = [],
        array $headers = [],
        ?string $user = null,
        ?string $password = null,
        ?int $timeout = null,
        ?AuthStrategy $authStrategy = null
    ): TwilioResponse {
        if ($params !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($params);
        }

        $request = Http::withHeaders($headers);

        if ($user !== null && $password !== null) {
            $request = $request->withBasicAuth($user, $password);
        }

        if ($timeout !== null) {
            $request = $request->timeout($timeout);
        }

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url),
            'DELETE' => $request->asForm()->delete($url, $data),
            'PUT' => $request->asForm()->put($url, $data),
            'PATCH' => $request->asForm()->patch($url, $data),
            default => $request->asForm()->post($url, $data),
        };

        return new TwilioResponse(
            $response->status(),
            $response->body(),
            collect($response->headers())->map(fn (array $values) => implode(', ', $values))->all(),
        );
    }
}
