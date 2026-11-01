<?php

return [

    // v2 = the fixed refresh job; legacy = the handler from the incident, kept
    // only so the failure can be reproduced (REFRESH_HANDLER=legacy).
    'handler' => env('REFRESH_HANDLER', 'v2'),

    'queue' => env('REFRESH_QUEUE', 'refresh'),

    // Profiles above this many likes refresh every 24 hours, the rest (including
    // exactly 100,000) every 72 hours.
    'likes_threshold' => 100_000,
    'interval_hours' => [
        'popular' => 24,
        'default' => 72,
    ],

    'http' => [
        'connect_timeout' => (int) env('REFRESH_HTTP_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('REFRESH_HTTP_TIMEOUT', 10),
        // A profile is a few KB; anything this large is not a profile.
        'max_body_bytes' => 1_000_000,
    ],

    // Job $timeout; must stay below the Horizon supervisor timeout and the
    // redis retry_after (see config/horizon.php).
    'job_timeout' => 30,

    // Upstream attempts allowed per refresh, per kind of failure. Delays grow
    // exponentially from base_seconds up to cap_seconds, randomised ("equal
    // jitter") so retries from many jobs do not arrive together.
    'retry' => [
        'rate_limited' => ['max' => 8, 'base_seconds' => (float) env('REFRESH_RATE_LIMIT_BASE', 2), 'cap_seconds' => 60],
        'server_error' => ['max' => 3, 'base_seconds' => (float) env('REFRESH_RETRY_BASE', 2), 'cap_seconds' => 60],
        'timeout' => ['max' => 3, 'base_seconds' => (float) env('REFRESH_RETRY_BASE', 2), 'cap_seconds' => 60],
    ],
    'max_upstream_attempts' => 10,

    // How many refresh jobs of one account may call the upstream at the same
    // time, so one busy or rate-limited account cannot take every worker.
    'account_concurrency' => (int) env('REFRESH_ACCOUNT_CONCURRENCY', 2),

    // After a refresh fails for good, try the profile again this much later,
    // doubling with each consecutive failure up to the 72-hour interval, so a
    // deleted profile or a revoked key is not hammered every hour.
    'failed_retry_after_minutes' => 60,

    // A claim (refresh_queued_at) older than this is treated as lost, e.g. the
    // dispatch never reached Redis. Longer than a refresh's retries can take
    // (at most 10 attempts, each delay capped at 60 s).
    'claim_expires_after_minutes' => 30,

    // Base URL of the local fake upstream used by workload:run.
    'fake_upstream_url' => env('FAKE_UPSTREAM_URL', 'http://fake-upstream:8080'),

    // Legacy handler only: blocking in-process retries, as in the incident.
    'legacy' => [
        'tries' => 3,
        'sleep_ms' => (int) env('REFRESH_LEGACY_SLEEP_MS', 1500),
    ],

];
