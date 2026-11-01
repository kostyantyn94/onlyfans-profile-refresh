<?php

namespace App\Refresh;

use App\Jobs\RefreshProfile;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Upstream\FansApiClient;
use App\Upstream\InvalidProfilePayload;
use App\Upstream\ProfilePayloadParser;
use App\Upstream\UpstreamResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * One upstream attempt for a refresh: fetch, validate, store or classify the
 * failure, then either finish the refresh or put the job back with a delay.
 */
class ProfileRefresher
{
    public function __construct(
        private FansApiClient $client,
        private ProfilePayloadParser $parser,
        private ProfileStore $store,
        private AccountThrottle $throttle,
    ) {}

    public function refresh(RefreshProfile $job): void
    {
        $profile = Profile::query()->with('account')->find($job->profileId);

        if ($profile === null) {
            Log::channel('refresh')->warning('refresh.profile_missing', $this->context($job));

            return;
        }

        $attempt = RefreshAttempt::query()->where('refresh_id', $job->refreshId)->count() + 1;
        $context = $this->context($job, $profile, $attempt);

        try {
            $response = $this->client->fetchProfile($profile->account, $profile->username);
        } catch (ConnectionException $e) {
            $this->failed($job, $profile, $attempt, Outcome::Timeout, $this->clean($e->getMessage()), null, $context);

            return;
        }

        if ($response->status < 200 || $response->status >= 300) {
            $reason = "HTTP {$response->status}".($response->body === '' ? ' with an empty body' : '');
            $this->failed($job, $profile, $attempt, Outcome::fromHttpStatus($response->status), $reason, $response, $context);

            return;
        }

        try {
            $snapshot = $this->parser->parse($response->body, $response->sentAtMs);
        } catch (InvalidProfilePayload $e) {
            $this->failed($job, $profile, $attempt, Outcome::InvalidResponse, 'invalid response: '.$e->getMessage(), $response, $context);

            return;
        }

        $this->crashForTest($job, 'before-write', $context);

        $outcome = $this->store->apply($profile, $snapshot, $job->refreshId, CarbonImmutable::now());

        $this->recordAttempt($job, $profile, $attempt, $outcome, $response, revision: $snapshot->revision, likes: $snapshot->likes);

        Log::channel('refresh')->info('refresh.'.$outcome->value, [
            ...$context,
            'outcome' => $outcome->value,
            'http_status' => $response->status,
            'format' => $snapshot->format,
            'revision' => $snapshot->revision,
            'likes' => $snapshot->likes,
            'duration_ms' => $response->durationMs,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);

        $this->crashForTest($job, 'after-write', $context);
    }

    /** The job is out of time or kept throwing: finish the refresh as failed. */
    public function giveUp(RefreshProfile $job, ?Throwable $e): void
    {
        $profile = Profile::query()->find($job->profileId);

        if ($profile === null) {
            return;
        }

        $reason = 'gave up: '.($e ? class_basename($e).': '.$this->clean($e->getMessage()) : 'unknown');
        $this->store->recordFailure($profile, Outcome::GaveUp, $reason, $job->refreshId, true, CarbonImmutable::now());

        Log::channel('refresh')->error('refresh.gave_up', [...$this->context($job, $profile), 'reason' => $reason]);
    }

    private function failed(RefreshProfile $job, Profile $profile, int $attempt, Outcome $outcome, string $reason, ?UpstreamResponse $response, array $context): void
    {
        $now = CarbonImmutable::now();
        $delay = $outcome->isRetryable() ? $this->retryDelay($job, $profile, $attempt, $outcome, $response, $now) : null;
        $final = $delay === null;

        $this->store->recordFailure($profile, $outcome, $reason, $job->refreshId, $final, $now);
        $this->recordAttempt($job, $profile, $attempt, $outcome, $response, retryIn: $delay, error: $reason);

        Log::channel('refresh')->log($final ? 'error' : 'warning', $final ? 'refresh.failed' : 'refresh.retry_scheduled', [
            ...$context,
            'outcome' => $outcome->value,
            'http_status' => $response?->status,
            'reason' => $reason,
            'retry_in_seconds' => $delay,
            'final' => $final,
            'duration_ms' => $response?->durationMs,
        ]);

        if (! $final) {
            $job->release($delay);
        }
    }

    /** Seconds until the next try, or null when this kind of failure is out of retries. */
    private function retryDelay(RefreshProfile $job, Profile $profile, int $attempt, Outcome $outcome, ?UpstreamResponse $response, CarbonImmutable $now): ?int
    {
        $budget = config('refresh.retry.'.$outcome->value);
        $sameKind = RefreshAttempt::query()
            ->where('refresh_id', $job->refreshId)
            ->where('outcome', $outcome->value)
            ->count() + 1;

        if ($sameKind >= $budget['max'] || $attempt >= (int) config('refresh.max_upstream_attempts')) {
            return null;
        }

        $delay = Backoff::seconds($sameKind, (float) $budget['base_seconds'], (float) $budget['cap_seconds']);

        if ($outcome === Outcome::RateLimited) {
            // The limit belongs to the account, not to this profile: pause the
            // whole account so its other jobs stop hitting the limit too.
            $delay = Backoff::retryAfter($response?->retryAfter, $now, 300) ?? $delay;
            $this->throttle->coolDown($profile->account_id, $delay);
        }

        return $delay;
    }

    private function recordAttempt(RefreshProfile $job, Profile $profile, int $attempt, Outcome $outcome, ?UpstreamResponse $response, ?int $revision = null, ?int $likes = null, ?int $retryIn = null, ?string $error = null): void
    {
        RefreshAttempt::query()->create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'refresh_id' => $job->refreshId,
            'job_id' => $job->job?->getJobId(),
            'job_attempt' => $job->job ? $job->attempts() : null,
            'handler' => 'v2',
            'outcome' => $outcome->value,
            'http_status' => $response?->status,
            'revision' => $revision,
            'likes' => $likes,
            'retry_in_seconds' => $retryIn,
            'error' => $error === null ? null : mb_strimwidth($error, 0, 500),
            'duration_ms' => $response?->durationMs ?? 0,
        ]);
    }

    private function context(RefreshProfile $job, ?Profile $profile = null, ?int $attempt = null): array
    {
        return array_filter([
            'refresh_id' => $job->refreshId,
            'account_id' => $job->accountId,
            'account' => $profile?->account?->slug,
            'profile_id' => $job->profileId,
            'username' => $profile?->username,
            'job_id' => $job->job?->getJobId(),
            'job_attempt' => $job->job ? $job->attempts() : null,
            'upstream_attempt' => $attempt,
        ], fn ($value) => $value !== null);
    }

    /**
     * Test seam for `php artisan workload:crash`, local environment only: a one-shot
     * Redis flag makes this worker process die (SIGKILL) at the given point, before
     * the queue is told the job finished, exactly like a crashed or OOM-killed worker.
     */
    private function crashForTest(RefreshProfile $job, string $point, array $context): void
    {
        if (! app()->isLocal() || Redis::del('refresh:crash:'.$point.':'.$job->profileId) !== 1) {
            return;
        }

        Log::channel('refresh')->warning('refresh.worker_killed_for_test', [...$context, 'point' => $point]);
        posix_kill(getmypid(), SIGKILL);
    }

    /** Guzzle messages include the URL; make sure no query string or token ends up in logs. */
    private function clean(string $message): string
    {
        return mb_strimwidth(preg_replace('/(Bearer\s+)\S+|\?\S*/i', '$1[redacted]', $message) ?? '', 0, 300);
    }
}
