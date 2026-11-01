<?php

namespace App\Jobs;

use App\Jobs\Middleware\AccountGate;
use App\Refresh\ProfileRefresher;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Refresh one profile from the upstream. Each run is one upstream attempt;
 * retries are releases with a delay, bounded by per-outcome budgets
 * (config/refresh.php) and by retryUntil().
 */
class RefreshProfile implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    // Exceptions we did not expect (database down, a bug), as opposed to
    // upstream failures, which are handled and never thrown.
    public int $maxExceptions = 3;

    public function __construct(
        public int $profileId,
        public int $accountId,
        public string $refreshId,
    ) {
        $this->onQueue(config('refresh.queue'));
        $this->timeout = (int) config('refresh.job_timeout');
    }

    /** Fixed at dispatch time; releases by the gate do not extend it. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('refresh.retry_window_minutes'));
    }

    public function middleware(): array
    {
        return [
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
