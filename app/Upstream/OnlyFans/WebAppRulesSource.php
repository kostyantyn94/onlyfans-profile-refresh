<?php

namespace App\Upstream\OnlyFans;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Signing rules read from OnlyFans' own web app, the way a browser gets them:
 * the page lists its scripts; app.js holds the request headers (app-token) and
 * names the module that signs; one of the page's small scripts holds that module,
 * which WebAppRulesExtractor reads. Nothing downloaded is executed.
 *
 * Rules are kept per web app release (the release is in the script paths), so
 * app.js (~800 KB) is only fetched again when OnlyFans ships a new release.
 */
class WebAppRulesSource
{
    public function __construct(private WebAppRulesExtractor $extractor) {}

    /** @throws InvalidSigningRules */
    public function load(string $pageUrl, float $deadline): SigningRules
    {
        $started = microtime(true);
        $html = $this->get($pageUrl, $deadline, 1_000_000);

        preg_match_all('/<script[^>]*\bsrc=["\']?([^"\'\s>]+\.js)/i', $html, $m);
        $scripts = array_values(array_unique(array_filter($m[1],
            fn (string $url) => preg_match('#^https://[a-z0-9.-]*\.onlyfans\.com/#', $url) === 1)));

        $release = preg_match('#/static/prod/\w+/(\d{12}-[a-f0-9]{10})/#', implode(' ', $scripts), $r) ? $r[1] : null;
        $source = 'webapp:'.$pageUrl;

        if ($release !== null && is_array($cached = Cache::get($this->cacheKey($release)))) {
            return SigningRules::fromArray($cached, $source);
        }

        // A release that could not be read is not downloaded again on every reload
        // (a rejected signature reloads the rules at once): the next source is used meanwhile.
        $failed = $release === null ? null : Cache::get($this->failedKey($release));

        if (is_array($failed) && (int) $failed['at'] > now()->subMinutes(10)->timestamp) {
            throw new InvalidSigningRules("{$failed['problem']} (release {$release}, not retried for 10 minutes)");
        }

        try {
            $rules = $this->read($scripts, $source, $release, $deadline, $started);
        } catch (InvalidSigningRules $e) {
            if ($release !== null) {
                Cache::put($this->failedKey($release), ['problem' => $e->getMessage(), 'at' => now()->timestamp], now()->addHour());
            }

            throw $e;
        }

        if ($release !== null) {
            Cache::put($this->cacheKey($release), $rules->toArray(), now()->addDays(7));
        }

        return $rules;
    }

    /** @param  list<string>  $scripts  the page's scripts */
    private function read(array $scripts, string $source, ?string $release, float $deadline, float $started): SigningRules
    {
        $appUrl = current(array_filter($scripts, fn (string $url) => str_ends_with($url, '/app.js')))
            ?: throw new InvalidSigningRules('app.js not found in the page');
        [$appToken, $signerId] = $this->headersModule($this->get($appUrl, $deadline, 5_000_000));

        // The signing module sits in one of the page's own small scripts.
        $candidates = array_filter($scripts, fn (string $url) => ! str_ends_with($url, '/app.js') && ! str_contains($url, 'chunk-vendors'));

        foreach (array_slice($candidates, 0, 6) as $url) {
            $script = $this->get($url, $deadline, 1_000_000);

            if (str_contains($script, $signerId.':function(')) {
                $rules = $this->extractor->extract($script, $appToken, $source, $release);

                Log::channel('refresh')->info('onlyfans.webapp_rules_read', [
                    'release' => $release,
                    'signing_script' => basename($url),
                    'signing_module' => $signerId,
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                ]);

                return $rules;
            }
        }

        throw new InvalidSigningRules("signing module {$signerId} not found in the page's scripts");
    }

    /**
     * In app.js: const t={...(0,J.A)(e)};t["app-token"]=Pe;... where J=s(802313) is
     * the signing module and Pe="33d5...". Both are looked up in the same module.
     *
     * @return array{0: string, 1: string} the app token and the signing module's id
     */
    private function headersModule(string $app): array
    {
        if (! preg_match('/const (\w+)=\{\.\.\.\(0,(\w+)\.\w+\)\(\w+\)\};\1\["app-token"\]=(\w+);/', $app, $h, PREG_OFFSET_CAPTURE)) {
            throw new InvalidSigningRules('request headers not found in app.js');
        }

        preg_match_all('/\d+:function\(\w+,\w+,\w+\)\{/', substr($app, max(0, $h[0][1] - 200_000), min($h[0][1], 200_000)), $starts, PREG_OFFSET_CAPTURE);
        $from = max(0, $h[0][1] - 200_000) + (end($starts[0])[1] ?? 0);
        $module = substr($app, $from, $h[0][1] - $from);

        $signer = preg_match('/[,;{ ]'.preg_quote($h[2][0], '/').'=\w+\((\d+)\)/', $module, $s) ? $s[1] : null;
        $token = preg_match('/[,;{ ]'.preg_quote($h[3][0], '/').'="([a-f0-9]{32})"/', $module, $t) ? $t[1] : null;

        if ($signer === null || $token === null) {
            throw new InvalidSigningRules('signing module or app token not found in app.js');
        }

        return [$token, $signer];
    }

    private function get(string $url, float $deadline, int $maxBytes): string
    {
        $left = $deadline - microtime(true);

        if ($left < 1) {
            throw new InvalidSigningRules('no time left to read the web app');
        }

        $response = Http::withUserAgent((string) config('services.onlyfans.user_agent'))
            ->connectTimeout(min(2, (int) ceil($left)))
            ->timeout(min(5, (int) floor($left)))
            ->get($url);

        if (! $response->successful()) {
            throw new InvalidSigningRules('HTTP '.$response->status().' for '.$url);
        }

        $body = $response->body();

        return strlen($body) <= $maxBytes ? $body : throw new InvalidSigningRules('unexpectedly large: '.$url);
    }

    private function cacheKey(string $release): string
    {
        return 'onlyfans:webapp-rules:'.$release;
    }

    private function failedKey(string $release): string
    {
        return 'onlyfans:webapp-rules-failed:'.$release;
    }
}
