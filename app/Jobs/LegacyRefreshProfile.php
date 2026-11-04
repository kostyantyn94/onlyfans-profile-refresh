<?php

namespace App\Jobs;

use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Upstream\UpstreamClients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * The refresh handler as it was during the incident, kept only to reproduce
 * it (REFRESH_HANDLER=legacy). Do not use. Its bugs, on purpose:
 *
 *  - reads `likes` from the top level only and turns a missing value into 0;
 *  - marks every response as a successful refresh, including 429 and 500;
 *  - writes whatever revision arrives, so a late old response wins;
 *  - retries inside the job with sleeps (as Http::retry does), holding a worker
 *    while a rate-limited account waits, so other accounts queue behind it.
 *
 * It calls the upstream through the same client as the fixed job, so before and
 * after are measured against the same requests.
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

    public function handle(UpstreamClients $clients): void
    {
        $profile = Profile::query()->with('account')->findOrFail($this->profileId);
        $client = $clients->for($profile->account);
        $tries = (int) config('refresh.legacy.tries');
        $started = hrtime(true);

        for ($try = 1; ; $try++) {
            try {
                $response = $client->fetchProfile($profile->account, $profile->username);
            } catch (ConnectionException $e) {
                if ($try >= $tries) {
                    throw $e;
                }

                Sleep::for((int) config('refresh.legacy.sleep_ms'))->milliseconds();

                continue;
            }

            if (($response->status >= 200 && $response->status < 300) || $try >= $tries) {
                break;
            }

            Sleep::for((int) config('refresh.legacy.sleep_ms'))->milliseconds();
        }

        $json = json_decode($response->body, true);
        $likes = $json['likes'] ?? 0;
        $revision = $json['revision'] ?? null;

        $profile->forceFill([
            'likes_count' => $likes,
            'revision' => $revision,
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
            'http_status' => $response->status,
            'revision' => $revision,
            'likes' => $likes,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        Log::channel('refresh')->info('refresh.completed', [
            'refresh_id' => $this->refreshId,
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'handler' => 'legacy',
            'http_status' => $response->status,
            'likes' => $likes,
        ]);
    }
}
