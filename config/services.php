<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'fansapi' => [
        'base_url' => env('FANSAPI_BASE_URL', 'https://app.onlyfansapi.com'),
        'key' => env('FANSAPI_KEY'),
        // ?fresh=true costs 1 credit per request; cached answers are free.
        'fresh' => (bool) env('FANSAPI_FRESH', false),
        // A cached snapshot older than this is not a refresh: ask again with
        // ?fresh=true (1 credit).
        'max_cache_age_minutes' => (int) env('FANSAPI_MAX_CACHE_AGE_MINUTES', 60),
    ],

];
