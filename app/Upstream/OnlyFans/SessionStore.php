<?php

namespace App\Upstream\OnlyFans;

use App\Models\Account;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * One OnlyFans session per account, shared by every worker through the cache
 * (encrypted: its cookies are credentials).
 *
 *  - Guest (default): user id "0", a random x-bc, and the cookies OnlyFans sets
 *    on the first responses. Enough for public profiles. Dropped after a
 *    rejection, so the next request starts a new one.
 *  - Logged in: the account's credential holds what a browser session sends
 *    (user id, cookie header, x-bc, user agent). The session starts from it and
 *    then follows the cookies OnlyFans sets, until the credential changes or is
 *    removed.
 */
class SessionStore
{
    public function for(Account $account): Session
    {
        $credential = $account->onlyFansCredential();
        $stored = $this->load($account);

        if ($credential !== null) {
            $basis = hash('sha256', json_encode($credential));

            if ($stored?->basis === $basis) {
                return $stored;
            }

            $session = new Session(
                (string) $credential['user_id'],
                (string) $credential['x_bc'],
                (string) ($credential['user_agent'] ?? config('services.onlyfans.user_agent')),
                Session::parseCookieHeader((string) $credential['cookie']),
                $basis,
            );
            Cache::put($this->key($account), $this->encode($session), $this->ttl());

            return $session;
        }

        if ($stored !== null && ! $stored->isGuest()) {
            // The account's login was removed: its session goes with it.
            $this->forget($account);
            $stored = null;
        }

        if ($stored !== null) {
            return $stored;
        }

        if (Cache::has($this->key($account))) {
            // Stored but unreadable (e.g. APP_KEY rotated): it would block the new one below.
            $this->forget($account);
        }

        // First writer wins, so concurrent workers end up sharing one session.
        Cache::add($this->key($account), $this->encode(Session::guest((string) config('services.onlyfans.user_agent'))), $this->ttl());

        return $this->load($account) ?? Session::guest((string) config('services.onlyfans.user_agent'));
    }

    /**
     * Apply a response's Set-Cookie headers to the session as it is stored now,
     * under a short per-account lock: concurrent responses do not undo each
     * other's cookies, and a session dropped meanwhile (after a rejection) is not
     * brought back by a late response that was sent with it.
     *
     * @param  list<string>  $setCookieHeaders
     */
    public function update(Account $account, Session $sentWith, array $setCookieHeaders): void
    {
        if ($setCookieHeaders === []) {
            return;
        }

        Cache::lock('onlyfans:session-lock:'.$account->id, 5)->block(1, function () use ($account, $sentWith, $setCookieHeaders) {
            $current = $this->load($account);

            if ($current === null || $current->xBc !== $sentWith->xBc || $current->basis !== $sentWith->basis) {
                return;
            }

            Cache::put($this->key($account), $this->encode($current->withSetCookies($setCookieHeaders)), $this->ttl());
        });
    }

    /**
     * Drop the account's session. With $sentWith, only if it is still that
     * session: a late rejection of an old session must not drop the new one
     * another worker has started meanwhile.
     */
    public function forget(Account $account, ?Session $sentWith = null): void
    {
        if ($sentWith === null) {
            Cache::forget($this->key($account));

            return;
        }

        Cache::lock('onlyfans:session-lock:'.$account->id, 5)->block(1, function () use ($account, $sentWith) {
            $current = $this->load($account);

            if ($current !== null && $current->xBc === $sentWith->xBc && $current->basis === $sentWith->basis) {
                Cache::forget($this->key($account));
            }
        });
    }

    private function load(Account $account): ?Session
    {
        $payload = Cache::get($this->key($account));

        try {
            return is_string($payload) ? Session::fromArray(json_decode(Crypt::decryptString($payload), true)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function encode(Session $session): string
    {
        return Crypt::encryptString(json_encode($session->toArray()));
    }

    private function key(Account $account): string
    {
        return 'onlyfans:session:'.$account->id;
    }

    private function ttl(): DateTimeInterface
    {
        return now()->addHours((int) config('services.onlyfans.session_ttl_hours'));
    }
}
