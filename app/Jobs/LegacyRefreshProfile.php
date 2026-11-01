<?php

namespace App\Jobs;

use App\Models\Profile;
use App\Models\RefreshAttempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The refresh handler as it was during the incident, kept only to reproduce
 * it (REFRESH_HANDLER=legacy). Do not use. Its bugs, on purpose:
 *
 *  - reads `likes` from the top level only and turns a missing value into 0;
 *  - marks every response as a successful refresh, including 429 and 500;
 *  - writes whatever revision arrives, so a late old response wins;
 *  - retries inside the job with sleeps (Http::retry), holding a worker while
 *    a rate-limited account waits, so other accounts queue behind it.
 */
class LegacyRefreshProfile implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $profileId,
        public int $accountId,
        public string $refreshId,
    ) {
        $this->onQueue(config('refresh.queue'));
    }

    public function handle(): void
    {
        $profile = Profile::query()->with('account')->findOrFail($this->profileId);
        $started = hrtime(true);

        $response = Http::baseUrl($profile->account->base_url)
            ->withToken((string) $profile->account->credential())
            ->acceptJson()
            ->timeout(10)
            ->retry((int) config('refresh.legacy.tries'), (int) config('refresh.legacy.sleep_ms'), throw: false)
            ->get('api/profiles/'.$profile->username);

        $likes = $response->json('likes') ?? 0;

        $profile->forceFill([
            'likes_count' => $likes,
            'revision' => $response->json('revision'),
            'last_attempt_at' => now(),
            'last_attempt_status' => 'success',
            'last_success_at' => now(),
            'next_refresh_at' => now()->addHours($likes > 100_000 ? 24 : 72),
            'refresh_queued_at' => null,
            'pending_refresh_id' => null,
        ])->save();

        RefreshAttempt::query()->create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'refresh_id' => $this->refreshId,
            'job_id' => $this->job?->getJobId(),
            'job_attempt' => $this->job ? $this->attempts() : null,
            'handler' => 'legacy',
            'outcome' => 'success',
            'http_status' => $response->status(),
            'revision' => $response->json('revision'),
            'likes' => $likes,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        Log::channel('refresh')->info('refresh.completed', [
            'refresh_id' => $this->refreshId,
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'handler' => 'legacy',
            'http_status' => $response->status(),
            'likes' => $likes,
        ]);
    }
}
