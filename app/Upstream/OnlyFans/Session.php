<?php

namespace App\Upstream\OnlyFans;

use GuzzleHttp\Cookie\SetCookie;

/**
 * What an OnlyFans browser session sends with every api2 request: the user id
 * ("0" for a guest), the x-bc token the web app keeps in local storage, the
 * user agent, and the cookies OnlyFans has set (sess, __cf_bm, ...).
 *
 * Cookies keep their expiry (__cf_bm lives 30 minutes) and are not sent once it
 * has passed. Domain and Path are not kept: a session belongs to one account,
 * whose requests all go to the same host, and OnlyFans sets its cookies for "/".
 */
final readonly class Session
{
    /**
     * @param  array<string, string>  $cookies  name => value
     * @param  string  $basis  what the session was built from: "guest", or a hash of the account's credentials
     * @param  array<string, int>  $expires  name => Unix time the cookie expires (none: until the session ends)
     */
    public function __construct(
        public string $userId,
        public string $xBc,
        public string $userAgent,
        public array $cookies,
        public string $basis,
        public array $expires = [],
    ) {}

    public static function guest(string $userAgent): self
    {
        // The web app's x-bc is a random 40-character hex token, kept per browser.
        return new self('0', bin2hex(random_bytes(20)), $userAgent, [], 'guest');
    }

    public function isGuest(): bool
    {
        return $this->basis === 'guest';
    }

    public function cookieHeader(): string
    {
        $cookies = $this->liveCookies();

        return implode('; ', array_map(fn ($name, $value) => $name.'='.$value, array_keys($cookies), $cookies));
    }

    /** @return array<string, string> the cookies whose expiry has not passed */
    public function liveCookies(): array
    {
        $now = now()->timestamp;

        return array_filter($this->cookies, fn (string $name) => ($this->expires[$name] ?? PHP_INT_MAX) > $now, ARRAY_FILTER_USE_KEY);
    }

    /**
     * The session after a response: cookies set, replaced or deleted (an expiry
     * in the past or Max-Age=0) in the order the headers arrived.
     *
     * @param  list<string>  $setCookieHeaders
     */
    public function withSetCookies(array $setCookieHeaders): self
    {
        $cookies = $this->liveCookies();
        $expires = array_intersect_key($this->expires, $cookies);

        foreach ($setCookieHeaders as $header) {
            $cookie = SetCookie::fromString($header);
            $name = $cookie->getName();
            $value = (string) $cookie->getValue();

            // Not SetCookie::validate(): it wants a Domain attribute, which
            // OnlyFans leaves out of its own sess cookie.
            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1 || preg_match('/[\s;,"\\\\]/', $value) === 1) {
                continue;
            }

            // Max-Age wins over Expires, as in browsers.
            $expiresAt = match (true) {
                $cookie->getMaxAge() !== null => now()->timestamp + (int) $cookie->getMaxAge(),
                is_numeric($cookie->getExpires()) => (int) $cookie->getExpires(),
                default => null,
            };

            if ($value === '' || ($expiresAt !== null && $expiresAt <= now()->timestamp)) {
                unset($cookies[$name], $expires[$name]);

                continue;
            }

            $cookies[$name] = $value;
            unset($expires[$name]);

            if ($expiresAt !== null) {
                $expires[$name] = $expiresAt;
            }
        }

        return new self($this->userId, $this->xBc, $this->userAgent, $cookies, $this->basis, $expires);
    }

    /** Cookies from a "name=value; name2=value2" header, as copied from a browser. */
    public static function parseCookieHeader(string $header): array
    {
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($name !== '' && $value !== null && preg_match('/[\s,]/', $name.$value) !== 1) {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'x_bc' => $this->xBc,
            'user_agent' => $this->userAgent,
            'cookies' => $this->cookies,
            'basis' => $this->basis,
            'expires' => $this->expires,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self((string) $data['user_id'], (string) $data['x_bc'], (string) $data['user_agent'], (array) $data['cookies'], (string) $data['basis'], (array) ($data['expires'] ?? []));
    }
}
