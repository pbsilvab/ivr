<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'number' => env('TWILIO_NUMBER'),
        'workspace_sid' => env('TWILIO_WORKSPACE_SID'),
        'workflow_sid' => env('TWILIO_WORKFLOW_SID'),
        'task_queue_sid' => env('TWILIO_TASKQUEUE_SID'),
        'activity_available_sid' => env('TWILIO_ACTIVITY_AVAILABLE_SID'),
        'activity_unavailable_sid' => env('TWILIO_ACTIVITY_UNAVAILABLE_SID'),

        // Browser dialer (softphone that plays the role of an external caller).
        'twiml_app_sid' => env('TWILIO_TWIML_APP_SID'),
        'api_key_sid' => env('TWILIO_API_KEY_SID'),
        'api_key_secret' => env('TWILIO_API_KEY_SECRET'),

        'dialer' => [
            // Caller ID the customer leg shows. Must be a Twilio number on the account or a
            // verified number; defaults to the app's own number.
            'caller_id' => env('TWILIO_DIALER_CALLER_ID') ?: env('TWILIO_NUMBER'),

            // Only these destinations may be dialed from the browser. The dialer exists to call
            // the app's own number, so anything else is refused — an unauthenticated token
            // endpoint plus an open <Dial> would be a toll-fraud invitation.
            'allowed_numbers' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) (env('TWILIO_DIALER_ALLOWED_NUMBERS') ?: env('TWILIO_NUMBER'))),
            ))),

            'token_ttl' => (int) env('TWILIO_DIALER_TOKEN_TTL', 3600),
        ],
    ],

];
