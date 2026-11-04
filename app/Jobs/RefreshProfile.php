<?php

namespace App\Jobs;

use App\Jobs\Middleware\AccountGate;
use App\Jobs\Middleware\SkipUnclaimedRefresh;
use App\Refresh\ProfileRefresher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Refresh one profile from the upstream. Each run is one upstream attempt;
 * retries are releases with a delay, bounded by per-outcome budgets counted in
 * refresh_attempts (config/refresh.php). There is deliberately no retryUntil()
 * and no fixed number of tries: both would also count time spent waiting in
 * the queue or deferred by the account gate, and fail refreshes that never
 * reached the upstream exactly when the queue is backed up.
 *
 * A worker that dies on this job (OOM, fatal error) counts towards
 * $maxExceptions too, so a job that kills workers cannot loop forever.
 */
#[CountCrashesAsExceptions]
class RefreshProfile implements ShouldQueue
{
    use Queueable;

    // 0 = no limit from the worker: the per-outcome budgets decide. Set here
    // because a plain `queue:work` defaults to --tries=1, which would fail
    // every retry (Horizon's supervisor is configured with tries 0 too).
    public int $tries = 0;

    public int $timeout;

    // Exceptions we did not expect (database down, a bug), as opposed to
    // upstream failures, which are handled and never thrown.
    public int $maxExceptions = 3;

    // Seconds before a job that threw runs again: a database failover of a
    // minute does not use up $maxExceptions in milliseconds.
    public array $backoff = [10, 60];

    public function __construct(
        public int $profileId,
        public int $accountId,
        public string $refreshId,
    ) {
        $this->onQueue(config('refresh.queue'));
        $this->timeout = (int) config('refresh.job_timeout');
    }

    public function middleware(): array
    {
        return [
            new SkipUnclaimedRefresh,
            new AccountGate($this->accountId),
            // Two deliveries for the same profile never run side by side
            // (e.g. a duplicate dispatch, or a redelivery after retry_after).
            (new WithoutOverlapping('profile:'.$this->profileId))
                ->releaseAfter(5)
                ->expireAfter((int) config('refresh.job_timeout') + 15),
        ];
    }

    public function handle(ProfileRefresher $refresher): void
    {
        $refresher->refresh($this);
    }

    public function failed(?Throwable $e): void
    {
        app(ProfileRefresher::class)->giveUp($this, $e);
    }

    /** Shown in Horizon, so a profile's jobs can be found by tag. */
    public function tags(): array
    {
        return ['profile:'.$this->profileId, 'account:'.$this->accountId, 'refresh:'.$this->refreshId];
    }
}
