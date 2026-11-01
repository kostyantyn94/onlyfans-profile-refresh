<?php

namespace App\Jobs\Middleware;

use App\Models\Account;
use App\Refresh\AccountThrottle;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Keeps one account from taking every worker. A job runs only if its account
 * is not cooling down after a 429 and has a free concurrency slot; otherwise it
 * goes back to the queue with a short delay, without calling the upstream and
 * without spending a retry.
 */
class AccountGate
{
    public function __construct(private int $accountId) {}

    public function handle(object $job, Closure $next): void
    {
        $throttle = app(AccountThrottle::class);
        $cooldownMs = $throttle->cooldownRemainingMs($this->accountId);

        if ($cooldownMs > 0) {
            // Spread the waiting jobs out so they do not all return at once.
            $this->defer($job, (int) ceil($cooldownMs / 1000) + random_int(0, 2), 'account_cooldown');

            return;
        }

        $limit = Account::query()->whereKey($this->accountId)->value('max_concurrency')
            ?? (int) config('refresh.account_concurrency');

        Redis::funnel($throttle->funnelKey($this->accountId))
            ->limit(max(1, (int) $limit))
            // If a worker dies holding a slot, the slot frees itself after this.
            ->releaseAfter((int) config('refresh.job_timeout') + 15)
            ->block(0)
            ->then(
                fn () => $next($job),
                fn () => $this->defer($job, random_int(1, 3), 'account_concurrency'),
            );
    }

    private function defer(object $job, int $seconds, string $reason): void
    {
        Log::channel('refresh')->debug('refresh.deferred', [
            'refresh_id' => $job->refreshId ?? null,
            'profile_id' => $job->profileId ?? null,
            'account_id' => $this->accountId,
            'job_id' => $job->job?->getJobId(),
            'reason' => $reason,
            'delay_seconds' => $seconds,
        ]);

        $job->release($seconds);
    }
}
