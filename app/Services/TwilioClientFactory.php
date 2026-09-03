<?php

namespace App\Services;

use Twilio\Rest\Client;

class TwilioClientFactory
{
    public static function make(): Client
    {
        return new Client(
            username: config('services.twilio.sid'),
            password: config('services.twilio.token'),
            httpClient: new LaravelHttpTwilioClient,
        );
    }
}
