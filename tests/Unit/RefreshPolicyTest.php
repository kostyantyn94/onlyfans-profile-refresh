<?php

namespace Tests\Unit;

use App\Refresh\Backoff;
use App\Refresh\RefreshPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RefreshPolicyTest extends TestCase
{
    public static function tiers(): array
    {
        return [
            'zero' => [0, 72],
            'just below' => [99_999, 72],
            'exactly 100,000 belongs to the 72-hour group' => [100_000, 72],
            'just above' => [100_001, 24],
            'the task profile' => [120_000, 24],
            'madison420ivy' => [611_905, 24],
        ];
    }

    #[DataProvider('tiers')]
    public function test_refresh_interval_by_likes(int $likes, int $hours): void
    {
        $policy = new RefreshPolicy;
        $from = CarbonImmutable::parse('2026-11-01 12:00:00');

        $this->assertSame($hours, $policy->intervalHours($likes));
        $this->assertEquals($from->addHours($hours), $policy->nextRefreshAt($likes, $from));
    }

    public function test_backoff_grows_is_randomised_and_capped(): void
    {
        foreach ([1 => [1, 2], 2 => [2, 4], 3 => [4, 8], 4 => [8, 16], 10 => [30, 60]] as $attempt => [$min, $max]) {
            $seen = [];

            for ($i = 0; $i < 200; $i++) {
                $delay = Backoff::seconds($attempt, base: 2, cap: 60);
                $this->assertGreaterThanOrEqual($min, $delay, "attempt {$attempt}");
                $this->assertLessThanOrEqual($max, $delay, "attempt {$attempt}");
                $seen[$delay] = true;
            }

            if ($max - $min >= 2) {
                $this->assertGreaterThan(1, count($seen), "attempt {$attempt} should not always wait the same time");
            }
        }
    }

    public function test_retry_after_header(): void
    {
        $now = CarbonImmutable::parse('2026-11-01 12:00:00 UTC');

        $this->assertSame(7, Backoff::retryAfter('7', $now, 300));
        $this->assertSame(300, Backoff::retryAfter('86400', $now, 300), 'capped');
        $this->assertSame(30, Backoff::retryAfter('Sun, 01 Nov 2026 12:00:30 GMT', $now, 300));
        $this->assertSame(1, Backoff::retryAfter('Sun, 01 Nov 2026 11:00:00 GMT', $now, 300), 'a date in the past still waits a little');
        $this->assertNull(Backoff::retryAfter(null, $now, 300));
        $this->assertNull(Backoff::retryAfter('', $now, 300));
        $this->assertNull(Backoff::retryAfter('soon', $now, 300));
    }
}
