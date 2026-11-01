<?php

namespace App\Refresh;

use Carbon\CarbonImmutable;

class Backoff
{
    /**
     * Exponential delay with "equal jitter": half of it fixed, half random, so
     * a retry never comes back instantly and many retries do not line up.
     */
    public static function seconds(int $attempt, float $base, float $cap): int
    {
        $delay = min($cap, $base * (2 ** max(0, $attempt - 1)));
        $half = $delay / 2;

        return max(1, (int) ceil($half + random_int(0, (int) round($half * 1000)) / 1000));
    }

    /** Retry-After as seconds or an HTTP date; null when absent or unusable. */
    public static function retryAfter(?string $header, CarbonImmutable $now, int $cap): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        if (ctype_digit(trim($header))) {
            $seconds = (int) trim($header);
        } else {
            $date = strtotime($header);

            if ($date === false) {
                return null;
            }

            $seconds = $date - $now->getTimestamp();
        }

        return min($cap, max(1, $seconds));
    }
}
