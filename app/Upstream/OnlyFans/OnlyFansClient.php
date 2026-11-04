<?php

namespace App\Upstream\OnlyFans;

use App\Models\Account;
use App\Upstream\ProfileClient;
use App\Upstream\UpstreamResponse;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function fetchProfile(Account $account, string $username, bool $fresh = false, ?Closure $beforeSend = null): UpstreamResponse
    {
        $rules = $this->rules->for($account->base_url);
        $session = $this->sessions->for($account);

        $url = rtrim($account->base_url, '/').'/api2/v2/users/'.rawurlencode($username);
        // The signature covers the path and query as sent, including any prefix
        // in the base URL (the fake upstream tells accounts apart by one).
        $query = parse_url($url, PHP_URL_QUERY);
        $path = (string) parse_url($url, PHP_URL_PATH).(is_string($query) ? '?'.$query : '');
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

        // The web app sends its release with every request; so do we when the
        // rules were read from that release.
        if ($rules->release !== null) {
            $headers['x-of-rev'] = $rules->release;
        }

        if ($session->liveCookies() !== []) {
            $headers['Cookie'] = $session->cookieHeader();
        }

        if ($beforeSend !== null) {
            $beforeSend();
        }

        $started = hrtime(true);

        $response = Http::withHeaders($headers)
            ->withUserAgent($session->userAgent)
            ->connectTimeout((int) config('refresh.http.connect_timeout'))
            ->timeout((int) config('refresh.http.timeout'))
            ->get($url);

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $rejected = $this->signatureRejected($response->status(), $response->body());

        $this->afterResponse(fn () => $this->sessions->update($account, $session, $response->toPsrResponse()->getHeader('Set-Cookie')));
        $revision = $response->header('x-of-rev') ?: null;

        if ($rejected) {
            $this->afterResponse(fn () => $this->rules->reject($account->base_url, $rules, $session->xBc, $revision, $sentAtMs));
        } elseif ($response->successful() || $response->status() === 404) {
            // The signature passed: "User not found" is an answer too.
            $this->afterResponse(fn () => $this->rules->accepted($account->base_url, $rules, $revision));
        }

        if (($rejected || in_array($response->status(), [401, 403], true)) && $session->isGuest()) {
            // Start the next request from a new guest session: the rejection may
            // be the session's, not the rules'. A logged-in session is the
            // account's own and stays; its credential decides.
            $this->afterResponse(fn () => $this->sessions->forget($account, $session));
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
     * Session and rules bookkeeping after a response. It must not lose the
     * response (the request was made and will be recorded as an attempt), so a
     * failure here, e.g. Redis unavailable or the session lock busy, is logged.
     */
    private function afterResponse(callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            Log::channel('refresh')->warning('onlyfans.bookkeeping_failed', ['error' => class_basename($e).': '.mb_strimwidth($e->getMessage(), 0, 200)]);
        }
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
