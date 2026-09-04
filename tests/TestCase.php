<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * POST to a webhook the way Twilio does, with a valid X-Twilio-Signature.
     */
    protected function twilioPost(string $uri, array $params = []): TestResponse
    {
        return $this->post($uri, $params, [
            'X-Twilio-Signature' => $this->computeSignature(url($uri), $params),
        ]);
    }

    /**
     * Sign a payload the way Twilio does: the URL, then every parameter appended as key+value
     * **sorted by key**, HMAC-SHA1'd with the auth token.
     *
     * The sort is the whole point. Twilio does not post parameters in alphabetical order, so a
     * validator that concatenates them in arrival order only agrees with Twilio by luck.
     */
    protected function computeSignature(string $url, array $params, ?string $authToken = null): string
    {
        $authToken ??= (string) config('services.twilio.token');

        ksort($params);

        $data = $url;

        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : $value);
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }
}
