<?php

namespace App\Upstream\OnlyFans;

use GuzzleHttp\Cookie\SetCookie;

/**
 * What an OnlyFans browser session sends with every api2 request: the user id
 * ("0" for a guest), the x-bc token the web app keeps in local storage, the
 * user agent, and the cookies OnlyFans has set (sess, __cf_bm, ...).
 */
final readonly class Session
{
    /**
     * @param  array<string, string>  $cookies  name => value
     * @param  string  $basis  what the session was built from: "guest", or a hash of the account's credentials
     */
    public function __construct(
        public string $userId,
        public string $xBc,
        public string $userAgent,
        public array $cookies,
        public string $basis,
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
        return implode('; ', array_map(fn ($name, $value) => $name.'='.$value, array_keys($this->cookies), $this->cookies));
    }

    /**
     * The session after a response: cookies set, replaced or deleted (an expiry
     * in the past or Max-Age=0) in the order the headers arrived.
     *
     * @param  list<string>  $setCookieHeaders
     */
    public function withSetCookies(array $setCookieHeaders): self
    {
        $cookies = $this->cookies;

        foreach ($setCookieHeaders as $header) {
            $cookie = SetCookie::fromString($header);
            $name = $cookie->getName();
            $value = (string) $cookie->getValue();

            // Not SetCookie::validate(): it wants a Domain attribute, which
            // OnlyFans leaves out of its own sess cookie.
            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1 || preg_match('/[\s;,"\\\\]/', $value) === 1) {
                continue;
            }

            $deleted = $value === '' || $cookie->isExpired() || ($cookie->getMaxAge() !== null && $cookie->getMaxAge() <= 0);

            if ($deleted) {
                unset($cookies[$name]);
            } else {
                $cookies[$name] = $value;
            }
        }

        return new self($this->userId, $this->xBc, $this->userAgent, $cookies, $this->basis);
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
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self((string) $data['user_id'], (string) $data['x_bc'], (string) $data['user_agent'], (array) $data['cookies'], (string) $data['basis']);
    }
}
