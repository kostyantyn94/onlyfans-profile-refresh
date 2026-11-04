<?php

namespace App\Upstream\OnlyFans;

/**
 * The values OnlyFans' web app currently signs api2 requests with. They change
 * with OnlyFans releases, so they are loaded at run time (SigningRulesProvider),
 * never hard-coded.
 *
 * Accepted shapes, as published by the public rule feeds:
 *  - {"static_param", "prefix", "suffix", "checksum_indexes", "checksum_constant", "app-token"}
 *  - {"static_param", "start", "end", ...,  "app_token"}
 *  - {"static_param", "format": "<prefix>:{}:{:x}:<suffix>", ..., "app_token", "remove_headers"}
 */
final readonly class SigningRules
{
    /**
     * @param  list<int>  $checksumIndexes  positions in the 40-character SHA-1 hex digest
     * @param  list<string>  $removeHeaders  headers the web app does not send with these rules
     */
    public function __construct(
        public string $staticParam,
        public string $prefix,
        public string $suffix,
        public array $checksumIndexes,
        public int $checksumConstant,
        public string $appToken,
        public array $removeHeaders = [],
        // Where the rules were loaded from, for logs.
        public string $source = '',
    ) {}

    /** @throws InvalidSigningRules */
    public static function fromArray(array $raw, string $source = ''): self
    {
        $prefix = $raw['prefix'] ?? $raw['start'] ?? null;
        $suffix = $raw['suffix'] ?? $raw['end'] ?? null;

        if (($prefix === null || $suffix === null) && is_string($raw['format'] ?? null)) {
            // "65232:{}:{:x}:6aa95d0a": prefix before the first colon, suffix after the last.
            $parts = explode(':', $raw['format']);
            $prefix ??= $parts[0];
            $suffix ??= end($parts);
        }

        $staticParam = $raw['static_param'] ?? null;
        $appToken = $raw['app_token'] ?? $raw['app-token'] ?? null;
        $indexes = $raw['checksum_indexes'] ?? null;
        $constant = $raw['checksum_constant'] ?? null;

        $problems = array_keys(array_filter([
            'static_param' => ! is_string($staticParam) || preg_match('/^[A-Za-z0-9]{16,64}$/', $staticParam) !== 1,
            'prefix' => ! self::isToken($prefix),
            'suffix' => ! self::isToken($suffix),
            'app_token' => ! is_string($appToken) || preg_match('/^[a-f0-9]{32}$/', $appToken) !== 1,
            'checksum_indexes' => ! is_array($indexes) || $indexes === [] || count($indexes) > 64
                || array_filter($indexes, fn ($i) => ! is_int($i) || $i < 0 || $i > 39) !== [],
            'checksum_constant' => ! is_int($constant),
        ]));

        if ($problems !== []) {
            throw new InvalidSigningRules('missing or invalid: '.implode(', ', $problems));
        }

        $removeHeaders = array_values(array_intersect(
            array_map(fn ($header) => strtolower(str_replace('_', '-', (string) $header)), (array) ($raw['remove_headers'] ?? [])),
            ['user-id'],
        ));

        return new self($staticParam, (string) $prefix, (string) $suffix, array_values($indexes), $constant, $appToken, $removeHeaders, $source);
    }

    /** Identifies one set of rules, e.g. to remember that OnlyFans rejected it. */
    public function fingerprint(): string
    {
        return sha1(json_encode([$this->staticParam, $this->prefix, $this->suffix, $this->checksumIndexes, $this->checksumConstant, $this->appToken]));
    }

    public function sends(string $header): bool
    {
        return ! in_array($header, $this->removeHeaders, true);
    }

    public function toArray(): array
    {
        return [
            'static_param' => $this->staticParam,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'checksum_indexes' => $this->checksumIndexes,
            'checksum_constant' => $this->checksumConstant,
            'app_token' => $this->appToken,
            'remove_headers' => $this->removeHeaders,
        ];
    }

    private static function isToken(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[A-Za-z0-9]{1,16}$/', (string) $value) === 1;
    }
}
