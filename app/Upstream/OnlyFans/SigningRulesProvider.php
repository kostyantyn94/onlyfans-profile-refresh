<?php

namespace App\Upstream\OnlyFans;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The current signing rules per upstream host, shared by every worker through
 * the cache. Sources (services.onlyfans.rules_sources) are tried in order; the
 * first with valid rules that OnlyFans is not rejecting is used.
 *
 *  - Rules older than rules_ttl_minutes are reloaded by one worker while the
 *    others keep signing with the last good copy.
 *  - A load that finds no usable rules is remembered for 30 s, so jobs fail
 *    fast instead of all asking the sources again.
 *  - A rejected signature does not prove the rules are outdated: the session can
 *    be the cause. reject() marks the cached copy for reloading (the source may
 *    have newer rules) and counts the *sessions* that got rejected with these
 *    rules; only when rules_reject_limit different sessions were rejected within
 *    one rules_skip_minutes window are the rules skipped for the next source.
 *    Rules OnlyFans accepted in the last 10 minutes are not counted against:
 *    then it is the sessions that are refused, not the rules.
 *  - OnlyFans names its current release in every response (x-of-rev). A release
 *    newer than the one the rules were read from marks them for reloading at once.
 */
class SigningRulesProvider
{
    // Seconds a job waits for another worker that is loading the rules.
    private const LOCK_WAIT = 2;

    // Seconds a failed load is remembered.
    private const UNAVAILABLE_FOR = 30;

    // Seconds one load may take over all sources, so a job stays inside its timeout.
    private const LOAD_BUDGET = 12;

    // Rules accepted by OnlyFans this recently are not blamed for a rejection.
    private const PROVEN_FOR = 600;

    // A rejection reloads rules loaded longer ago than this (a new release: always),
    // so a burst of rejections from one bad session costs one reload, not one each.
    private const RELOAD_AFTER_REJECTION = 5;

    public function __construct(private WebAppRulesSource $webApp) {}

    /** @throws SigningRulesUnavailable */
    public function for(string $baseUrl): SigningRules
    {
        $host = $this->host($baseUrl);
        $cached = $this->cached($host);

        if ($cached !== null && ! $cached['stale']) {
            return $cached['rules'];
        }

        $failure = Cache::get($this->unavailableKey($host));

        if ($cached !== null) {
            // Past their TTL: one worker reloads, the others keep using them meanwhile.
            return $failure === null
                ? (Cache::lock($this->lockKey($host), 20)->get(fn () => $this->reload($host, $cached['rules'])) ?: $cached['rules'])
                : $cached['rules'];
        }

        if ($failure !== null) {
            throw new SigningRulesUnavailable($failure);
        }

        try {
            return Cache::lock($this->lockKey($host), 20)->block(self::LOCK_WAIT, function () use ($host) {
                // The worker that held the lock may have loaded them, or failed to.
                $failure = Cache::get($this->unavailableKey($host));

                return $this->cached($host)['rules']
                    ?? ($failure !== null ? throw new SigningRulesUnavailable($failure) : $this->reload($host, null));
            });
        } catch (LockTimeoutException) {
            throw new SigningRulesUnavailable("signing rules for {$host} are still being loaded by another worker", loading: true);
        }
    }

    /**
     * Load the rules from the sources now and use them from the next request on,
     * whatever is cached. For operators (php artisan onlyfans:rules --reload);
     * workers keep signing with the cached copy meanwhile.
     *
     * @throws SigningRulesUnavailable
     */
    public function reloadNow(string $baseUrl): SigningRules
    {
        $host = $this->host($baseUrl);

        try {
            return Cache::lock($this->lockKey($host), 20)->block(self::LOAD_BUDGET + 3, fn () => $this->store($host, $this->load($host)));
        } catch (LockTimeoutException) {
            throw new SigningRulesUnavailable("another worker has been loading the rules for {$host} too long", loading: true);
        }
    }

    /**
     * OnlyFans accepted a request signed with these rules: remembered for
     * PROVEN_FOR seconds (one SET NX per response), and their rejection count
     * starts over. A response from a newer web app release than the rules were
     * read from marks them for reloading.
     */
    public function accepted(string $baseUrl, SigningRules $rules, ?string $webAppRevision = null): void
    {
        $host = $this->host($baseUrl);

        if ($this->isNewerRelease($rules, $webAppRevision)) {
            $this->markForReload($host, $rules);
        }

        if (Cache::add($this->provenKey($host, $rules), now()->timestamp, self::PROVEN_FOR)) {
            Redis::del($this->rejectionsKey($host, $rules));
        }
    }

    /**
     * OnlyFans refused a request signed with these rules. Unless they were
     * accepted recently, remember which session was refused; once enough
     * different sessions were within one window, the rules are skipped for the
     * rest of it. The cached copy is reloaded (one worker, while the others keep
     * signing), or dropped if the rules are now skipped.
     *
     * @param  string  $sessionToken  the session's x-bc, stored only as a hash
     * @param  ?string  $webAppRevision  OnlyFans' x-of-rev header: its current web app release
     */
    public function reject(string $baseUrl, SigningRules $rules, string $sessionToken, ?string $webAppRevision = null): void
    {
        $host = $this->host($baseUrl);
        $key = $this->rejectionsKey($host, $rules);
        $proven = (int) Cache::get($this->provenKey($host, $rules)) > now()->timestamp - self::PROVEN_FOR;
        $newRelease = $this->isNewerRelease($rules, $webAppRevision);

        if (! $proven || $newRelease) {
            Redis::sadd($key, hash('sha256', $sessionToken));

            // A fixed window from the first rejection: rejections hours apart do not add up.
            if ((int) Redis::ttl($key) < 0) {
                Redis::expire($key, (int) config('services.onlyfans.rules_skip_minutes') * 60);
            }
        }

        $sessions = (int) Redis::scard($key);
        $skipped = $sessions >= (int) config('services.onlyfans.rules_reject_limit');
        $cached = Cache::get($this->cacheKey($host));

        // Another worker may have loaded newer rules meanwhile; keep those.
        if (($cached['fingerprint'] ?? null) === $rules->fingerprint()) {
            if ($skipped) {
                // No longer a last good copy to fall back on.
                Cache::forget($this->cacheKey($host));
            } elseif ($newRelease || (int) $cached['loaded_at'] < now()->timestamp - self::RELOAD_AFTER_REJECTION) {
                $this->markForReload($host, $rules);
            }
        }

        Log::channel('refresh')->warning('onlyfans.signature_rejected', [
            'host' => $host,
            'rules_source' => $rules->source,
            'rules' => substr($rules->fingerprint(), 0, 12),
            'rules_release' => $rules->release,
            'onlyfans_rev' => $webAppRevision,
            // Accepted by OnlyFans recently: the session is blamed, not the rules.
            'rules_proven' => $proven,
            'sessions_rejected' => $sessions,
            'skipped' => $skipped,
        ]);
    }

    private function isNewerRelease(SigningRules $rules, ?string $webAppRevision): bool
    {
        return $rules->release !== null && $webAppRevision !== null
            && preg_match('/^\d{12}-[a-f0-9]{10}$/', $webAppRevision) === 1
            && strcmp(substr($webAppRevision, 0, 12), substr($rules->release, 0, 12)) > 0;
    }

    /** Past their TTL from now on: the next request reloads them in one worker, the others keep signing. */
    private function markForReload(string $host, SigningRules $rules): void
    {
        $cached = Cache::get($this->cacheKey($host));

        if (($cached['fingerprint'] ?? null) === $rules->fingerprint() && (int) $cached['loaded_at'] > 0) {
            Cache::put($this->cacheKey($host), [...$cached, 'loaded_at' => 0], now()->addDay());
        }
    }

    /** @return array{rules: SigningRules, stale: bool}|null */
    private function cached(string $host): ?array
    {
        $cached = Cache::get($this->cacheKey($host));

        try {
            return is_array($cached) ? [
                'rules' => SigningRules::fromArray($cached['rules'], (string) $cached['source']),
                'stale' => (int) $cached['loaded_at'] < now()->subMinutes((int) config('services.onlyfans.rules_ttl_minutes'))->timestamp,
            ] : null;
        } catch (InvalidSigningRules) {
            return null;
        }
    }

    /** Load from the sources; if none has usable rules, keep the last good ones when there are any. */
    private function reload(string $host, ?SigningRules $lastGood): SigningRules
    {
        try {
            $rules = $this->load($host);
        } catch (SigningRulesUnavailable $e) {
            Cache::put($this->unavailableKey($host), $e->getMessage(), now()->addSeconds(self::UNAVAILABLE_FOR));

            if ($lastGood === null) {
                throw $e;
            }

            Log::channel('refresh')->warning('onlyfans.rules_reload_failed', ['host' => $host, 'kept' => substr($lastGood->fingerprint(), 0, 12)]);

            return $lastGood;
        }

        return $this->store($host, $rules);
    }

    private function store(string $host, SigningRules $rules): SigningRules
    {
        // Kept a day, but reloaded after rules_ttl_minutes (see cached()).
        Cache::put($this->cacheKey($host), [
            'rules' => $rules->toArray(),
            'source' => $rules->source,
            'fingerprint' => $rules->fingerprint(),
            'loaded_at' => now()->timestamp,
        ], now()->addDay());
        Cache::forget($this->unavailableKey($host));

        return $rules;
    }

    private function load(string $host): SigningRules
    {
        $sources = config('services.onlyfans.rules_sources')[$host] ?? [];
        $problems = [];
        $deadline = microtime(true) + self::LOAD_BUDGET;

        foreach ($sources as $source) {
            try {
                $rules = str_starts_with($source, 'webapp:')
                    ? $this->webApp->load(substr($source, strlen('webapp:')), $deadline)
                    : SigningRules::fromArray($this->read($source, $deadline), $source);
            } catch (Throwable $e) {
                $problems[] = $source.': '.$e->getMessage();

                continue;
            }

            if ($this->isSkipped($host, $rules)) {
                $problems[] = $source.': rejected by OnlyFans for '.config('services.onlyfans.rules_reject_limit').' sessions recently';

                continue;
            }

            Log::channel('refresh')->info('onlyfans.rules_loaded', [
                'host' => $host,
                'rules_source' => $source,
                'rules' => substr($rules->fingerprint(), 0, 12),
                'release' => $rules->release,
                // Earlier sources that gave nothing usable, and why (e.g. the web app's code changed shape).
                'passed_over' => $problems,
            ]);

            return $rules;
        }

        Log::channel('refresh')->error('onlyfans.rules_unavailable', ['host' => $host, 'problems' => $problems]);

        throw new SigningRulesUnavailable("no usable signing rules for {$host}: "
            .($problems === [] ? 'no source configured' : implode('; ', $problems)));
    }

    /** A source is "webapp:<page URL>" (see WebAppRulesSource), a rules URL, or a JSON file (absolute, or relative to the project). */
    private function read(string $source, float $deadline): array
    {
        if (preg_match('#^https?://#i', $source) === 1) {
            $left = $deadline - microtime(true);

            if ($left < 1) {
                throw new InvalidSigningRules('skipped: no time left in this load');
            }

            $response = Http::acceptJson()->connectTimeout(2)->timeout(min(3, (int) floor($left)))->get($source);

            if (! $response->successful()) {
                throw new InvalidSigningRules("HTTP {$response->status()}");
            }

            $body = $response->body();
        } else {
            $path = str_starts_with($source, '/') ? $source : base_path($source);
            $body = is_file($path) ? (string) file_get_contents($path) : throw new InvalidSigningRules('file not found');
        }

        if (strlen($body) > 65_536) {
            throw new InvalidSigningRules('larger than 64 KB');
        }

        $json = json_decode($body, true);

        return is_array($json) ? $json : throw new InvalidSigningRules('not a JSON object');
    }

    private function isSkipped(string $host, SigningRules $rules): bool
    {
        return (int) Redis::scard($this->rejectionsKey($host, $rules)) >= (int) config('services.onlyfans.rules_reject_limit');
    }

    private function host(string $baseUrl): string
    {
        return strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
    }

    private function cacheKey(string $host): string
    {
        return 'onlyfans:rules:'.$host;
    }

    private function unavailableKey(string $host): string
    {
        return 'onlyfans:rules-unavailable:'.$host;
    }

    private function lockKey(string $host): string
    {
        return 'onlyfans:rules-load:'.$host;
    }

    // In Redis directly (a set of hashed session tokens); the rest is in the cache.
    private function rejectionsKey(string $host, SigningRules $rules): string
    {
        return 'onlyfans:rules-rejected:'.$host.':'.$rules->fingerprint();
    }

    private function provenKey(string $host, SigningRules $rules): string
    {
        return 'onlyfans:rules-proven:'.$host.':'.$rules->fingerprint();
    }
}
