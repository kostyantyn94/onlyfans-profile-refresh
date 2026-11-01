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

    // Read and write in one step, so two racing 429s cannot shorten a cooldown.
    private const EXTEND_COOLDOWN = <<<'LUA'
        if redis.call('pttl', KEYS[1]) < tonumber(ARGV[1]) then
            return redis.call('set', KEYS[1], ARGV[2], 'PX', ARGV[1])
        end
        return 0
        LUA;

    /** Start or extend (never shorten) the account's cooldown. */
    public function coolDown(int $accountId, int $seconds): void
    {
        Redis::eval(self::EXTEND_COOLDOWN, 1, $this->cooldownKey($accountId), $seconds * 1000, time());
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
