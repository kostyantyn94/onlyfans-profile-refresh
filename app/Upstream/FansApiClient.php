<?php

namespace App\Upstream;

use App\Models\Account;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * GET {base_url}/api/profiles/{username} — Fans API "Get Profile Details"
 * (https://docs.onlyfansapi.com/api-reference/public-profiles/get-profile-details).
 * The local fake upstream serves the same path.
 */
class FansApiClient
{
    /**
     * Never throws for an HTTP status; the caller decides what each status means.
     *
     * @throws ConnectionException on connect/read timeouts and network errors
     */
    public function fetchProfile(Account $account, string $username): UpstreamResponse
    {
        $sentAtMs = (int) floor(microtime(true) * 1000);
        $started = hrtime(true);

        $response = Http::baseUrl($account->base_url)
            ->withToken((string) $account->credential())
            ->acceptJson()
            ->withUserAgent('profile-refresher/1.0')
            ->connectTimeout((int) config('refresh.http.connect_timeout'))
            ->timeout((int) config('refresh.http.timeout'))
            ->get('api/profiles/'.rawurlencode($username), config('services.fansapi.fresh') ? ['fresh' => 'true'] : []);

        return new UpstreamResponse(
            status: $response->status(),
            body: $response->body(),
            retryAfter: $response->header('Retry-After') ?: null,
            sentAtMs: $sentAtMs,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
        );
    }
}
