<?php

return [

    // For a public demo behind a reverse proxy (e.g. Tailscale Funnel): requests
    // that arrive through the proxy (they carry X-Forwarded-For) can only read:
    // no tracking, no "refresh now" (visitors would trigger upstream requests),
    // no Horizon dashboard. Requests made directly on the machine are unaffected.
    'read_only_when_proxied' => (bool) env('PUBLIC_READ_ONLY', false),

    // The public base URL, so links and assets use it (and https) behind the proxy.
    'public_url' => env('PUBLIC_URL'),

];
