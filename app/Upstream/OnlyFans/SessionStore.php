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
 *    (user id, cookie header, x-bc, user agent). Cookies OnlyFans rotates later
 *    are kept on top, until the credential itself changes.
 */
class SessionStore
{
    public function for(Account $account): Session
    {
        $credential = $account->onlyFansCredential();
        $stored = $this->load($account);

        if ($credential !== null) {
            $basis = hash('sha256', json_encode($credential));

            return new Session(
                (string) $credential['user_id'],
                (string) $credential['x_bc'],
                (string) ($credential['user_agent'] ?? config('services.onlyfans.user_agent')),
                [...Session::parseCookieHeader((string) $credential['cookie']), ...($stored?->basis === $basis ? $stored->cookies : [])],
                $basis,
            );
        }

        if ($stored?->isGuest()) {
            return $stored;
        }

        // First writer wins, so concurrent workers end up sharing one session.
        Cache::add($this->key($account), $this->encode(Session::guest((string) config('services.onlyfans.user_agent'))), $this->ttl());

        return $this->load($account) ?? Session::guest((string) config('services.onlyfans.user_agent'));
    }

    /** @param  list<string>  $setCookieHeaders */
    public function update(Account $account, Session $session, array $setCookieHeaders): void
    {
        if ($setCookieHeaders !== []) {
            Cache::put($this->key($account), $this->encode($session->withSetCookies($setCookieHeaders)), $this->ttl());
        }
    }

    public function forget(Account $account): void
    {
        Cache::forget($this->key($account));
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
