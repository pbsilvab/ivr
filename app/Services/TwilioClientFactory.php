<?php

namespace App\Services;

use Twilio\Rest\Client;

class TwilioClientFactory
{
    public static function make(): Client
    {
        return new Client(
            config('services.twilio.sid'),
            config('services.twilio.token'),
        );
    }
}
