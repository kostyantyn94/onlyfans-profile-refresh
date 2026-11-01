<?php

namespace App\Refresh;

use Illuminate\Support\Facades\Redis;

/**
 * Per-account state in Redis that every worker sees: a cooldown started by a
 * 429, so the other jobs of that account wait instead of hitting the limit too.
 */
class AccountThrottle
{
    public function cooldownRemainingMs(int $accountId): int
    {
        return max(0, (int) Redis::pttl($this->cooldownKey($accountId)));
    }

    /** Start or extend (never shorten) the account's cooldown. */
    public function coolDown(int $accountId, int $seconds): void
    {
        if ($this->cooldownRemainingMs($accountId) < $seconds * 1000) {
            Redis::set($this->cooldownKey($accountId), (string) time(), 'PX', $seconds * 1000);
        }
    }

    public function funnelKey(int $accountId): string
    {
        return 'refresh:funnel:account:'.$accountId;
    }

    private function cooldownKey(int $accountId): string
    {
        return 'refresh:cooldown:account:'.$accountId;
    }
}
