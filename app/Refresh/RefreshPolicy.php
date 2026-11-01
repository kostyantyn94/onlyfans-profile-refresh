<?php

namespace App\Refresh;

use Carbon\CarbonImmutable;

class RefreshPolicy
{
    /** Above the threshold: every 24 hours. Exactly 100,000 and below: every 72. */
    public function intervalHours(int $likes): int
    {
        return $likes > (int) config('refresh.likes_threshold')
            ? (int) config('refresh.interval_hours.popular')
            : (int) config('refresh.interval_hours.default');
    }

    public function nextRefreshAt(int $likes, CarbonImmutable $from): CarbonImmutable
    {
        return $from->addHours($this->intervalHours($likes));
    }
}
