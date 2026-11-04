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
 *    be the cause. reject() drops the cached copy (the source may have newer
 *    rules) and counts the *sessions* that got rejected with these rules; only
 *    when rules_reject_limit different sessions were rejected within
 *    rules_skip_minutes are the rules skipped for the next source.
 */
class SigningRulesProvider
{
    // Seconds a job waits for another worker that is loading the rules.
    private const LOCK_WAIT = 2;

    // Seconds a failed load is remembered.
    private const UNAVAILABLE_FOR = 30;

    // Seconds one load may take over all sources, so a job stays inside its timeout.
    private const LOAD_BUDGET = 12;

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
            return Cache::lock($this->lockKey($host), 20)->block(self::LOCK_WAIT,
                fn () => $this->cached($host)['rules'] ?? $this->reload($host, null));
        } catch (LockTimeoutException) {
            throw new SigningRulesUnavailable("signing rules for {$host} are still being loaded by another worker");
        }
    }

    /**
     * OnlyFans refused a request signed with these rules. Forget the cached copy
     * and remember which session was refused; once enough different sessions
     * were, the rules are skipped for a while.
     *
     * @param  string  $sessionToken  the session's x-bc, stored only as a hash
     * @param  ?string  $webAppRevision  OnlyFans' x-of-rev header: a new value marks a release
     */
    public function reject(string $baseUrl, SigningRules $rules, string $sessionToken, ?string $webAppRevision = null): void
    {
        $host = $this->host($baseUrl);
        $key = $this->rejectionsKey($host, $rules);

        Redis::sadd($key, hash('sha256', $sessionToken));
        Redis::expire($key, (int) config('services.onlyfans.rules_skip_minutes') * 60);
        $sessions = (int) Redis::scard($key);

        // Another worker may have loaded newer rules meanwhile; keep those.
        if ((Cache::get($this->cacheKey($host))['fingerprint'] ?? null) === $rules->fingerprint()) {
            Cache::forget($this->cacheKey($host));
        }

        Log::channel('refresh')->warning('onlyfans.signature_rejected', [
            'host' => $host,
            'rules_source' => $rules->source,
            'rules' => substr($rules->fingerprint(), 0, 12),
            'sessions_rejected' => $sessions,
            'skipped' => $sessions >= (int) config('services.onlyfans.rules_reject_limit'),
            'onlyfans_rev' => $webAppRevision,
        ]);
    }

    /** Forget the rules in use for the host, so the next request loads them from the sources again. */
    public function forget(string $baseUrl): void
    {
        $host = $this->host($baseUrl);

        Cache::forget($this->cacheKey($host));
        Cache::forget($this->unavailableKey($host));
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
}
