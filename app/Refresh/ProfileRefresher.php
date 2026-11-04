<?php

namespace App\Refresh;

use App\Jobs\RefreshProfile;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Upstream\InvalidProfilePayload;
use App\Upstream\OnlyFans\SigningRulesUnavailable;
use App\Upstream\ProfilePayloadParser;
use App\Upstream\ProfileSnapshot;
use App\Upstream\UpstreamClients;
use App\Upstream\UpstreamResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
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
        private UpstreamClients $clients,
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

        if ($profile->pending_refresh_id !== $job->refreshId) {
            // This refresh no longer holds the claim: an earlier delivery of the
            // same job already finished it (e.g. redelivered after its worker
            // died), or the claim expired and a newer refresh took over.
            Log::channel('refresh')->info('refresh.skipped_not_pending', $this->context($job, $profile));

            return;
        }

        $attempt = RefreshAttempt::query()->where('refresh_id', $job->refreshId)->count() + 1;
        $context = $this->context($job, $profile, $attempt);

        if ($attempt > $this->attemptBudget()) {
            // Only reachable through a redelivery (a worker died after its last
            // call): the budget is checked before every call.
            $this->budgetUsedUp($job, $profile, $context);

            return;
        }

        $fresh = (bool) config('services.fansapi.fresh');

        // Every response goes through the same checks, the follow-up included;
        // the loop runs at most twice (cache first, then live data once).
        while (true) {
            $result = $this->fetchValid($job, $profile, $attempt, $context, $fresh);

            if ($result === null) {
                return;
            }

            [$response, $snapshot, $row] = $result;

            if (! $this->cacheTooOld($snapshot)) {
                break;
            }

            $reason = 'snapshot cached at '.$snapshot->cachedAt->toIso8601String().' is older than '
                .config('services.fansapi.max_cache_age_minutes').' min';

            if ($fresh) {
                $this->failed($job, $profile, $row, $attempt, Outcome::CacheStillTooOld, $reason.' even with ?fresh=true', $response, $context);

                return;
            }

            if ($attempt >= $this->attemptBudget()) {
                // No attempt left for the live request: a failed refresh, never a stale success.
                $this->failed($job, $profile, $row, $attempt, Outcome::CacheTooOld, $reason.'; no attempt left to ask for live data', $response, $context);

                return;
            }

            // Fans API answered from a cached snapshot older than we accept: that
            // is not a refresh. Record it and ask once more for live data.
            $this->finishAttempt($row, Outcome::CacheTooOld, $response, revision: $snapshot->revision, likes: $snapshot->likes);
            Log::channel('refresh')->info('refresh.cache_too_old', [...$context, 'cached_at' => $snapshot->cachedAt->toIso8601String()]);

            $context['upstream_attempt'] = ++$attempt;
            $fresh = true;
        }

        $this->crashForTest($job, 'before-write', $context);

        // The profile write and the attempt's result commit together: a crash
        // leaves either both or neither, never data without its audit row.
        $outcome = DB::transaction(function () use ($job, $profile, $snapshot, $row, $response) {
            $outcome = $this->store->apply($profile, $snapshot, $job->refreshId, CarbonImmutable::now());
            $this->finishAttempt($row, $outcome, $response, revision: $snapshot->revision, likes: $snapshot->likes);

            return $outcome;
        });

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

    /**
     * One upstream call that ends in a validated snapshot, or null after the
     * failure has been recorded (and the job released or finished).
     *
     * @return array{0: UpstreamResponse, 1: ProfileSnapshot, 2: RefreshAttempt}|null
     */
    private function fetchValid(RefreshProfile $job, Profile $profile, int $attempt, array $context, bool $fresh): ?array
    {
        $row = null;

        try {
            $response = $this->clients->for($profile->account)->fetchProfile($profile->account, $profile->username, $fresh,
                // The attempt is recorded right before the request goes out, in its own
                // commit, so the budget counts every request sent: also one whose worker
                // dies before the answer is stored (it stays "in_flight", and a
                // redelivery makes the next one). Nothing is recorded if building the
                // request fails (e.g. Redis down): nothing was sent.
                function () use (&$row, $job, $profile) {
                    $row = $this->startAttempt($job, $profile);
                });
        } catch (ConnectionException $e) {
            $this->failed($job, $profile, $row, $attempt, Outcome::Timeout, $this->clean($e->getMessage()), null, $context);

            return null;
        } catch (SigningRulesUnavailable $e) {
            if ($e->loading) {
                // Another worker is loading the rules this moment: nothing was sent and
                // nothing is decided yet, so no attempt is spent. Try again shortly.
                Log::channel('refresh')->debug('refresh.deferred', [...$context, 'reason' => 'signing_rules_loading', 'delay_seconds' => 3]);
                $job->release(3);

                return null;
            }

            // No usable rules: recorded as an attempt although nothing was sent, so
            // the retry budget bounds how long a refresh waits for them.
            $this->failed($job, $profile, $row, $attempt, Outcome::RulesUnavailable, $e->getMessage(), null, $context);

            return null;
        }

        if ($response->signatureRejected) {
            $this->failed($job, $profile, $row, $attempt, Outcome::SignatureRejected, 'HTTP 400: OnlyFans rejected the request signature', $response, $context);

            return null;
        }

        if ($response->status < 200 || $response->status >= 300) {
            $reason = "HTTP {$response->status}".($response->body === '' ? ' with an empty body' : '');
            $this->failed($job, $profile, $row, $attempt, Outcome::fromHttpStatus($response->status), $reason, $response, $context);

            return null;
        }

        try {
            $snapshot = $this->parser->parse($response->body, $response->sentAtMs);
            $this->assertSameProfile($profile, $snapshot);

            return [$response, $snapshot, $row];
        } catch (InvalidProfilePayload $e) {
            $this->failed($job, $profile, $row, $attempt, Outcome::InvalidResponse, 'invalid response: '.$e->getMessage(), $response, $context);

            return null;
        }
    }

    /**
     * The answer must be the profile that was asked for: never write one
     * creator's data into another's row (a misrouted response, or a username
     * that now belongs to a different account).
     *
     * @throws InvalidProfilePayload
     */
    private function assertSameProfile(Profile $profile, ProfileSnapshot $snapshot): void
    {
        // Only the real upstream formats carry an identity; the incident's test formats do not.
        if (! in_array($snapshot->format, ['onlyfans', 'fansapi'], true)) {
            return;
        }

        $username = $snapshot->raw['username'] ?? null;

        if (is_string($username) && strcasecmp($username, $profile->username) !== 0) {
            throw new InvalidProfilePayload("the answer is for {$username}, not {$profile->username}");
        }

        $id = $snapshot->attributes['upstream_id'] ?? null;

        if ($id !== null && $profile->upstream_id !== null && (int) $id !== (int) $profile->upstream_id) {
            throw new InvalidProfilePayload("upstream id {$id}, but {$profile->username} was {$profile->upstream_id}: the username belongs to another account now");
        }
    }

    private function cacheTooOld(ProfileSnapshot $snapshot): bool
    {
        return $snapshot->cachedAt !== null
            && $snapshot->cachedAt->lt(CarbonImmutable::now()->subMinutes((int) config('services.fansapi.max_cache_age_minutes')));
    }

    /** Upstream calls allowed per refresh, follow-ups included. */
    private function attemptBudget(): int
    {
        return (int) config('refresh.max_upstream_attempts');
    }

    /** The refresh has no upstream attempt left: finish it as failed without calling. */
    private function budgetUsedUp(RefreshProfile $job, Profile $profile, array $context): void
    {
        $reason = 'all '.$this->attemptBudget().' upstream attempts used';
        $this->store->recordFailure($profile, Outcome::GaveUp, $reason, $job->refreshId, true, CarbonImmutable::now());

        Log::channel('refresh')->error('refresh.failed', [...$context, 'outcome' => Outcome::GaveUp->value, 'reason' => $reason, 'final' => true]);
    }

    /** The job kept throwing or crashing its worker: finish the refresh as failed. */
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

    private function failed(RefreshProfile $job, Profile $profile, ?RefreshAttempt $row, int $attempt, Outcome $outcome, string $reason, ?UpstreamResponse $response, array $context): void
    {
        $now = CarbonImmutable::now();
        $sameKind = RefreshAttempt::query()
            ->where('refresh_id', $job->refreshId)
            ->where('outcome', $outcome->value)
            ->count() + 1;
        $delay = $this->backoffSeconds($outcome, $sameKind, $response, $now);

        if ($outcome === Outcome::RateLimited) {
            // The limit belongs to the account, not to this profile: pause the
            // whole account, even when this refresh is out of retries.
            $this->throttle->coolDown($profile->account_id, $delay);
        }

        $final = ! $outcome->isRetryable()
            || $sameKind >= config('refresh.retry.'.$outcome->value.'.max')
            || $attempt >= $this->attemptBudget();

        if ($final) {
            $delay = null;
        }

        DB::transaction(function () use ($job, $profile, $row, $outcome, $reason, $response, $final, $delay, $now) {
            $this->store->recordFailure($profile, $outcome, $reason, $job->refreshId, $final, $now);
            // No row when nothing was sent (no usable rules): recorded all the same, so
            // the retry budget bounds how long a refresh waits for them.
            $this->finishAttempt($row ?? $this->startAttempt($job, $profile), $outcome, $response, retryIn: $delay, error: $reason);
        });

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

    /** Delay before the next try of this kind of failure: Retry-After if sent, else randomised exponential. */
    private function backoffSeconds(Outcome $outcome, int $sameKind, ?UpstreamResponse $response, CarbonImmutable $now): int
    {
        $budget = config('refresh.retry.'.$outcome->value) ?? config('refresh.retry.server_error');
        $delay = Backoff::seconds($sameKind, (float) $budget['base_seconds'], (float) $budget['cap_seconds']);

        // Retry-After is honoured where it is sent: 429, and 503 during maintenance.
        return in_array($outcome, [Outcome::RateLimited, Outcome::ServerError], true)
            ? Backoff::retryAfter($response?->retryAfter, $now, 300) ?? $delay
            : $delay;
    }

    private function startAttempt(RefreshProfile $job, Profile $profile): RefreshAttempt
    {
        return RefreshAttempt::query()->create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'refresh_id' => $job->refreshId,
            'job_id' => $job->job?->getJobId(),
            'job_attempt' => $job->job ? $job->attempts() : null,
            'handler' => 'v2',
            'outcome' => Outcome::InFlight->value,
            'duration_ms' => 0,
        ]);
    }

    private function finishAttempt(RefreshAttempt $row, Outcome $outcome, ?UpstreamResponse $response, ?int $revision = null, ?int $likes = null, ?int $retryIn = null, ?string $error = null): void
    {
        $row->update([
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
