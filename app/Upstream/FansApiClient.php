<?php

namespace App\Upstream;

use App\Models\Account;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * GET {base_url}/api/profiles/{username} — Fans API "Get Profile Details"
 * (https://docs.onlyfansapi.com/api-reference/public-profiles/get-profile-details).
 * The managed alternative to OnlyFansClient, for accounts with the "fansapi"
 * driver; the first version of this service used only this.
 */
class FansApiClient implements ProfileClient
{
    /**
     * Never throws for an HTTP status; the caller decides what each status means.
     *
     * @throws ConnectionException on connect/read timeouts and network errors
     */
    public function fetchProfile(Account $account, string $username, bool $fresh = false, ?Closure $beforeSend = null): UpstreamResponse
    {
        $token = (string) $account->credential();

        if ($beforeSend !== null) {
            $beforeSend();
        }

        $sentAtMs = (int) floor(microtime(true) * 1000);
        $started = hrtime(true);

        $response = Http::baseUrl($account->base_url)
            ->withToken($token)
            ->acceptJson()
            ->withUserAgent('profile-refresher/1.0')
            ->connectTimeout((int) config('refresh.http.connect_timeout'))
            ->timeout((int) config('refresh.http.timeout'))
            ->get('api/profiles/'.rawurlencode($username), $fresh || config('services.fansapi.fresh') ? ['fresh' => 'true'] : []);

        return new UpstreamResponse(
            status: $response->status(),
            body: $response->body(),
            retryAfter: $response->header('Retry-After') ?: null,
            sentAtMs: $sentAtMs,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
        );
    }
}
