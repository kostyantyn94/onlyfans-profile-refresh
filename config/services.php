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

    // Direct requests to onlyfans.com/api2/v2, signed by App\Upstream\OnlyFans\RequestSigner.
    'onlyfans' => [
        'base_url' => env('ONLYFANS_BASE_URL', 'https://onlyfans.com'),

        // Where the current signing rules come from, per upstream host, tried in
        // order: "webapp:<page>" reads them from OnlyFans' own web app code
        // (WebAppRulesSource); a URL or a local JSON file gives them ready-made
        // (public rule feeds, or rules pinned by hand). Rules that OnlyFans keeps
        // rejecting are skipped (see SigningRulesProvider).
        'rules_sources' => [
            // An empty ONLYFANS_RULES_SOURCES= in .env means the defaults too.
            'onlyfans.com' => array_values(array_filter(explode(',', (string) (env('ONLYFANS_RULES_SOURCES') ?:
                'webapp:https://onlyfans.com/,'
                .'https://raw.githubusercontent.com/rafa-9/dynamic-rules/main/rules.json,'
                .'https://raw.githubusercontent.com/DATAHOARDERS/dynamic-rules/main/onlyfans.json,'
                .'https://raw.githubusercontent.com/datawhores/onlyfans-dynamic-rules/main/dynamicRules.json,'
                .'https://raw.githubusercontent.com/riley-access-labs/onlyfans-dynamic-rules-1/main/dynamicRules.json')))),
            // The local fake upstream signs with its own test rules.
            parse_url((string) env('FAKE_UPSTREAM_URL', 'http://fake-upstream:8080'), PHP_URL_HOST) => [
                rtrim((string) env('FAKE_UPSTREAM_URL', 'http://fake-upstream:8080'), '/').'/rules.json',
            ],
        ],
        // Loaded rules are reused this long; a rejected signature reloads them sooner.
        'rules_ttl_minutes' => (int) env('ONLYFANS_RULES_TTL_MINUTES', 60),
        // Rules rejected this many times (each time with a new session) are
        // skipped for rules_skip_minutes in favour of the next source.
        'rules_reject_limit' => 2,
        'rules_skip_minutes' => 30,

        // Sent with every request of a session; OnlyFans ties the session to it.
        'user_agent' => env('ONLYFANS_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'),
        // A guest session (x-bc token and the cookies OnlyFans sets) is kept this long.
        'session_ttl_hours' => (int) env('ONLYFANS_SESSION_TTL_HOURS', 24),
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
