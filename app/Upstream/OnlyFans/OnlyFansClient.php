<?php

namespace App\Upstream\OnlyFans;

use App\Models\Account;
use App\Upstream\ProfileClient;
use App\Upstream\UpstreamResponse;
use Illuminate\Support\Facades\Http;

/**
 * GET {base_url}/api2/v2/users/{username}: the request OnlyFans' own web app
 * makes for a profile page, with the same signed headers (sign, time, app-token,
 * x-bc, user-id) and the session's cookies. A guest session is enough for a
 * public profile; an account with a logged-in credential sends its user id.
 */
class OnlyFansClient implements ProfileClient
{
    public function __construct(
        private SigningRulesProvider $rules,
        private RequestSigner $signer,
        private SessionStore $sessions,
    ) {}

    /** OnlyFans always answers with live data, so $fresh changes nothing here. */
    public function fetchProfile(Account $account, string $username, bool $fresh = false): UpstreamResponse
    {
        $rules = $this->rules->for($account->base_url);
        $session = $this->sessions->for($account);

        $url = rtrim($account->base_url, '/').'/api2/v2/users/'.rawurlencode($username);
        // The signature covers the path as sent, including any prefix in the
        // base URL (the fake upstream tells accounts apart by one).
        $path = (string) parse_url($url, PHP_URL_PATH);
        $sentAtMs = (int) floor(microtime(true) * 1000);

        $headers = [
            'Accept' => 'application/json, text/plain, */*',
            'app-token' => $rules->appToken,
            'sign' => $this->signer->sign($rules, $path, $sentAtMs, $session->userId),
            'time' => (string) $sentAtMs,
            'x-bc' => $session->xBc,
        ];

        if ($rules->sends('user-id')) {
            $headers['user-id'] = $session->userId;
        }

        if ($session->cookies !== []) {
            $headers['Cookie'] = $session->cookieHeader();
        }

        $started = hrtime(true);

        $response = Http::withHeaders($headers)
            ->withUserAgent($session->userAgent)
            ->connectTimeout((int) config('refresh.http.connect_timeout'))
            ->timeout((int) config('refresh.http.timeout'))
            ->get($url);

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->sessions->update($account, $session, $response->toPsrResponse()->getHeader('Set-Cookie'));

        $rejected = $this->signatureRejected($response->status(), $response->body());

        if ($rejected) {
            $this->rules->reject($account->base_url, $rules);
        }

        if ($rejected || in_array($response->status(), [401, 403], true)) {
            // Start the next request from a new session: the rejection may be
            // the session's, not the rules'.
            $this->sessions->forget($account);
        }

        return new UpstreamResponse(
            status: $response->status(),
            body: $response->body(),
            retryAfter: $response->header('Retry-After') ?: null,
            sentAtMs: $sentAtMs,
            durationMs: $durationMs,
            signatureRejected: $rejected,
        );
    }

    /**
     * How OnlyFans refuses a signature it does not accept (outdated rules, or a
     * session it no longer trusts): HTTP 400 with error code 401,
     * {"error":{"code":401,"message":"Please refresh the page"}}.
     */
    private function signatureRejected(int $status, string $body): bool
    {
        return $status === 400 && (json_decode($body, true)['error']['code'] ?? null) === 401;
    }
}
