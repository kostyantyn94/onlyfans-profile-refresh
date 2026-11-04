<?php

namespace App\Upstream\OnlyFans;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The current signing rules per upstream host, shared by every worker through
 * the cache. Sources (services.onlyfans.rules_sources) are tried in order; the
 * first with valid rules that OnlyFans has not kept rejecting is used.
 *
 * A rejected signature does not prove the rules are outdated: the guest session
 * can be the cause. So reject() only drops the cached copy (the source may have
 * published newer rules) while the client drops the session; rules rejected
 * rules_reject_limit times within rules_skip_minutes, each time with a new
 * session, are skipped in favour of the next source.
 */
class SigningRulesProvider
{
    /** @throws SigningRulesUnavailable */
    public function for(string $baseUrl): SigningRules
    {
        $host = $this->host($baseUrl);

        if ($rules = $this->cached($host)) {
            return $rules;
        }

        try {
            // One worker loads; the others wait for its result instead of all
            // fetching the sources at once.
            return Cache::lock('onlyfans:rules:load:'.$host, 20)->block(10, function () use ($host) {
                if ($rules = $this->cached($host)) {
                    return $rules;
                }

                $rules = $this->load($host);
                Cache::put($this->cacheKey($host), [
                    'rules' => $rules->toArray(),
                    'source' => $rules->source,
                    'fingerprint' => $rules->fingerprint(),
                ], now()->addMinutes((int) config('services.onlyfans.rules_ttl_minutes')));

                return $rules;
            });
        } catch (LockTimeoutException) {
            throw new SigningRulesUnavailable("signing rules for {$host} are still being loaded by another worker");
        }
    }

    /**
     * OnlyFans refused a request signed with these rules. Forget the cached copy
     * and count the rejection; at the limit the rules are skipped for a while.
     */
    public function reject(string $baseUrl, SigningRules $rules): void
    {
        $host = $this->host($baseUrl);
        $key = $this->rejectionsKey($host, $rules);

        Cache::add($key, 0, now()->addMinutes((int) config('services.onlyfans.rules_skip_minutes')));
        $rejections = (int) Cache::increment($key);

        // Another worker may have loaded newer rules meanwhile; keep those.
        if ((Cache::get($this->cacheKey($host))['fingerprint'] ?? null) === $rules->fingerprint()) {
            Cache::forget($this->cacheKey($host));
        }

        Log::channel('refresh')->warning('onlyfans.signature_rejected', [
            'host' => $host,
            'rules_source' => $rules->source,
            'rules' => substr($rules->fingerprint(), 0, 12),
            'rejections' => $rejections,
            'skipped' => $rejections >= (int) config('services.onlyfans.rules_reject_limit'),
        ]);
    }

    private function cached(string $host): ?SigningRules
    {
        $cached = Cache::get($this->cacheKey($host));

        try {
            return is_array($cached) ? SigningRules::fromArray($cached['rules'], (string) $cached['source']) : null;
        } catch (InvalidSigningRules) {
            return null;
        }
    }

    private function load(string $host): SigningRules
    {
        $sources = config('services.onlyfans.rules_sources')[$host] ?? [];
        $problems = [];

        foreach ($sources as $source) {
            try {
                $rules = SigningRules::fromArray($this->read($source), $source);
            } catch (Throwable $e) {
                $problems[] = $source.': '.$e->getMessage();

                continue;
            }

            if ($this->isSkipped($host, $rules)) {
                $problems[] = $source.': rejected by OnlyFans '.config('services.onlyfans.rules_reject_limit').' times recently';

                continue;
            }

            Log::channel('refresh')->info('onlyfans.rules_loaded', [
                'host' => $host,
                'rules_source' => $source,
                'rules' => substr($rules->fingerprint(), 0, 12),
            ]);

            return $rules;
        }

        Log::channel('refresh')->error('onlyfans.rules_unavailable', ['host' => $host, 'problems' => $problems]);

        throw new SigningRulesUnavailable("no usable signing rules for {$host}: "
            .($problems === [] ? 'no source configured' : implode('; ', $problems)));
    }

    /** A source is a URL or a JSON file (absolute, or relative to the project). */
    private function read(string $source): array
    {
        if (preg_match('#^https?://#i', $source) === 1) {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(4)->get($source);

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
        return (int) Cache::get($this->rejectionsKey($host, $rules), 0) >= (int) config('services.onlyfans.rules_reject_limit');
    }

    private function host(string $baseUrl): string
    {
        return strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
    }

    private function cacheKey(string $host): string
    {
        return 'onlyfans:rules:'.$host;
    }

    private function rejectionsKey(string $host, SigningRules $rules): string
    {
        return 'onlyfans:rules-rejected:'.$host.':'.$rules->fingerprint();
    }
}
