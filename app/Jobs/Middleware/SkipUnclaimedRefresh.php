<?php

namespace App\Jobs\Middleware;

use App\Models\Profile;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Runs before the account gate: a job whose refresh no longer holds the
 * profile's claim (finished by an earlier delivery, or replaced after the
 * claim expired) is dropped at once instead of being deferred again and again.
 */
class SkipUnclaimedRefresh
{
    public function handle(object $job, Closure $next): void
    {
        if (Profile::query()->whereKey($job->profileId)->value('pending_refresh_id') !== $job->refreshId) {
            Log::channel('refresh')->info('refresh.skipped_not_pending', [
                'refresh_id' => $job->refreshId,
                'profile_id' => $job->profileId,
                'account_id' => $job->accountId,
                'job_id' => $job->job?->getJobId(),
            ]);

            return;
        }

        $next($job);
    }
}
