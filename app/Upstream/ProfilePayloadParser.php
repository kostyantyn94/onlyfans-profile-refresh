<?php

namespace App\Upstream;

use Carbon\CarbonImmutable;
use JsonException;
use Throwable;

/**
 * Turns an upstream response body into a validated ProfileSnapshot, or throws
 * InvalidProfilePayload. Nothing is written to the database before this passes.
 *
 * Accepted formats:
 *  - Fans API:  {"data": {..., "favoritedCount": 611905}, "_meta": {"_cache": {"cached_at": "..."}}}
 *  - old:       {"likes": 120000, "revision": 10, ...}
 *  - new:       {"profile": {"likes": 121000, ...}, "revision": 11}
 *
 * Likes must be present and a whole number >= 0 (an explicit 0 is valid).
 */
class ProfilePayloadParser
{
    /** Optional profile fields: column => [upstream key, type]. */
    private const ATTRIBUTES = [
        'upstream_id' => ['id', 'count'],
        'name' => ['name', 'string'],
        'about' => ['about', 'text'],
        'avatar_url' => ['avatar', 'url'],
        'header_url' => ['header', 'url'],
        'is_verified' => ['isVerified', 'bool'],
        'subscribe_price' => ['subscribePrice', 'money'],
        'posts_count' => ['postsCount', 'count32'],
        'photos_count' => ['photosCount', 'count32'],
        'videos_count' => ['videosCount', 'count32'],
        'audios_count' => ['audiosCount', 'count32'],
        'medias_count' => ['mediasCount', 'count32'],
        'subscribers_count' => ['subscribersCount', 'count'],
        'joined_at' => ['joinDate', 'date'],
        'last_seen_at' => ['lastSeen', 'date'],
    ];

    /**
     * @param  int  $fallbackRevision  used when a Fans API response carries no cache
     *                                 timestamp: the time the request was sent (ms)
     */
    public function parse(string $body, int $fallbackRevision): ProfileSnapshot
    {
        if ($body === '') {
            throw new InvalidProfilePayload('empty body');
        }

        if (strlen($body) > (int) config('refresh.http.max_body_bytes')) {
            throw new InvalidProfilePayload('body is larger than '.config('refresh.http.max_body_bytes').' bytes');
        }

        try {
            $json = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new InvalidProfilePayload('body is not valid JSON: '.$e->getMessage());
        }

        if (! $this->isObject($json)) {
            throw new InvalidProfilePayload('body is not a JSON object');
        }

        return array_key_exists('data', $json)
            ? $this->fromFansApi($json, $fallbackRevision)
            : $this->fromRevisioned($json);
    }

    private function fromFansApi(array $json, int $fallbackRevision): ProfileSnapshot
    {
        $data = $json['data'];

        if (! $this->isObject($data)) {
            throw new InvalidProfilePayload('data is not an object');
        }

        if (! array_key_exists('favoritedCount', $data)) {
            throw new InvalidProfilePayload('data.favoritedCount (likes) is missing');
        }

        $likes = $this->wholeNumber($data['favoritedCount'], 'data.favoritedCount');

        // Fans API has no revision. The time Fans API took the snapshot from
        // OnlyFans grows with every new snapshot, so it orders responses the
        // same way; without it, fall back to when we sent the request.
        $cache = $json['_meta']['_cache'] ?? null;
        $cachedAtMs = $this->cachedAt($cache);
        $revision = $cachedAtMs ?? $fallbackRevision;
        $fromCache = is_array($cache) && ($cache['is_cached'] ?? false) === true && $cachedAtMs !== null;

        return new ProfileSnapshot($likes, $revision, 'fansapi', $this->attributes($data), $data,
            $fromCache ? CarbonImmutable::createFromTimestampMs($cachedAtMs) : null);
    }

    private function fromRevisioned(array $json): ProfileSnapshot
    {
        $nested = $json['profile'] ?? null;

        if ($nested !== null && ! $this->isObject($nested)) {
            throw new InvalidProfilePayload('profile is not an object');
        }

        $hasTop = array_key_exists('likes', $json);
        $hasNested = $nested !== null && array_key_exists('likes', $nested);

        if (! $hasTop && ! $hasNested) {
            throw new InvalidProfilePayload('likes is missing (looked at likes and profile.likes)');
        }

        $likes = $hasNested
            ? $this->wholeNumber($nested['likes'], 'profile.likes')
            : $this->wholeNumber($json['likes'], 'likes');

        if ($hasTop && $hasNested) {
            $top = $this->wholeNumber($json['likes'], 'likes');

            if ($top !== $likes) {
                throw new InvalidProfilePayload("likes conflict: likes={$top}, profile.likes={$likes}");
            }
        }

        if (! array_key_exists('revision', $json)) {
            throw new InvalidProfilePayload('revision is missing');
        }

        $revision = $this->wholeNumber($json['revision'], 'revision');
        $fields = $hasNested ? $nested : $json;

        return new ProfileSnapshot($likes, $revision, $hasNested ? 'new' : 'old', $this->attributes($fields), $fields);
    }

    private function wholeNumber(mixed $value, string $field): int
    {
        $number = match (true) {
            is_int($value) => $value,
            is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53 => (int) $value,
            is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 => (int) $value,
            default => throw new InvalidProfilePayload("{$field} is not a whole number: ".$this->describe($value)),
        };

        if ($number < 0) {
            throw new InvalidProfilePayload("{$field} is negative: {$number}");
        }

        return $number;
    }

    private function cachedAt(mixed $cache): ?int
    {
        if (! is_array($cache) || ! is_string($cache['cached_at'] ?? null)) {
            return null;
        }

        try {
            return (int) CarbonImmutable::parse($cache['cached_at'])->getTimestampMs();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Optional fields never fail the response: a field with an unexpected type,
     * or one that would not fit its column, is skipped (the stored value stays);
     * a missing field is left untouched.
     *
     * @return array<string, mixed>
     */
    private function attributes(array $fields): array
    {
        $attributes = [];

        foreach (self::ATTRIBUTES as $column => [$key, $type]) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }

            $value = $fields[$key];

            if ($value === null) {
                $attributes[$column] = null;

                continue;
            }

            $converted = $this->convert($value, $type);

            if ($converted !== null) {
                $attributes[$column] = $converted;
            }
        }

        return $attributes;
    }

    private function convert(mixed $value, string $type): mixed
    {
        try {
            return match ($type) {
                'string' => is_string($value) && mb_strlen($value) <= 255 ? $value : null,
                'text' => is_string($value) && strlen($value) <= 65_535 ? $value : null,
                'url' => is_string($value) && strlen($value) <= 2048 && filter_var($value, FILTER_VALIDATE_URL) ? $value : null,
                'bool' => is_bool($value) ? $value : null,
                'money' => (is_int($value) || is_float($value)) && $value >= 0 && $value < 100_000_000 ? round((float) $value, 2) : null,
                'count' => $this->wholeNumber($value, 'field'),
                'count32' => ($n = $this->wholeNumber($value, 'field')) <= 2_147_483_647 ? $n : null,
                'date' => is_string($value) ? CarbonImmutable::parse($value)->utc() : null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private function describe(mixed $value): string
    {
        return mb_strimwidth(json_encode($value, JSON_UNESCAPED_SLASHES) ?: gettype($value), 0, 60, '...');
    }
}
