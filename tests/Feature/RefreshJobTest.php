<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\AccountThrottle;
use App\Refresh\RefreshScheduler;
use App\Upstream\OnlyFans\SessionStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Behaviour of the fixed refresh job (RefreshProfile) beyond the incident cases. */
class RefreshJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['refresh.handler' => 'v2']);
    }

    public function test_a_429_pauses_the_whole_account(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response($this->fixture('rate-limited.json'), 429)]);

        $job = $this->runRefresh($profile);

        $cooldownMs = app(AccountThrottle::class)->cooldownRemainingMs($profile->account_id);
        $this->assertGreaterThan(0, $cooldownMs, 'other jobs of this account must wait too');
        $this->assertLessThanOrEqual($job->job->releaseDelay * 1000, $cooldownMs);
        $this->assertSame('rate_limited', RefreshAttempt::query()->sole()->outcome);
    }

    public function test_retry_after_is_honoured_when_present(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '7'])]);

        $job = $this->runRefresh($profile);

        $job->assertReleased(7);
    }

    public function test_rate_limits_give_up_after_the_retry_budget(): void
    {
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        $this->previousAttempts($profile, $refreshId, 'rate_limited', config('refresh.retry.rate_limited.max') - 1);
        Http::fake(['*' => Http::response('', 429)]);

        $job = $this->runRefresh($profile, $refreshId);

        $this->assertFalse($job->job->isReleased(), 'out of retries: no more attempts');
        $profile->refresh();
        $this->assertSame(120_000, $profile->likes_count);
        $this->assertSame('rate_limited', $profile->last_attempt_status);
        $this->assertNull($profile->refresh_queued_at, 'the claim is released so the scheduler can try again later');
        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, $profile->next_refresh_at->timestamp, 5);
    }

    public function test_server_errors_give_up_after_four_attempts(): void
    {
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        Http::fake(['*' => Http::response('', 503)]);

        $jobs = array_map(fn () => $this->runRefresh($profile, $refreshId), range(1, 4));

        $this->assertSame([true, true, true, false], array_map(fn ($job) => $job->job->isReleased(), $jobs));
        $this->assertSame(array_fill(0, 4, 'server_error'), RefreshAttempt::query()->orderBy('id')->pluck('outcome')->all());
        // One failed refresh, however many attempts it took: retried in an hour.
        $this->assertSame(1, $profile->refresh()->consecutive_failures);
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $profile->next_refresh_at->timestamp, 5);
    }

    public function test_a_refresh_that_runs_out_of_429_retries_is_one_failed_refresh(): void
    {
        $profile = $this->profileWithValidData(attributes: ['likes_count' => 611_905]);
        $refreshId = (string) Str::uuid();
        Http::fake(['*' => Http::response('', 429)]);

        foreach (range(1, config('refresh.retry.rate_limited.max')) as $i) {
            $this->runRefresh($profile, $refreshId);
        }

        $profile->refresh();
        $this->assertSame(8, $profile->attempts()->count());
        $this->assertSame(1, $profile->consecutive_failures, 'counted per refresh, not per attempt');
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $profile->next_refresh_at->timestamp, 5, 'not 2^7 hours');
        $this->assertNull($profile->pending_refresh_id);
    }

    public function test_a_timeout_is_retried_with_backoff(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));

        $job = $this->runRefresh($profile);

        $this->assertTrue($job->job->isReleased());
        $this->assertSame('timeout', $profile->refresh()->last_attempt_status);
        $this->assertSame(120_000, $profile->likes_count);
    }

    public static function permanentFailures(): array
    {
        return [
            'profile gone' => [404, 'not_found'],
            'profile deleted' => [410, 'not_found'],
            'bad key' => [401, 'auth_failed'],
            'forbidden' => [403, 'auth_failed'],
            'bad request' => [400, 'client_error'],
        ];
    }

    #[DataProvider('permanentFailures')]
    public function test_permanent_failures_are_not_retried(int $status, string $outcome): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response(['error' => 'nope'], $status)]);

        $job = $this->runRefresh($profile);

        $this->assertFalse($job->job->isReleased(), "HTTP {$status} must not be retried");
        $profile->refresh();
        $this->assertSame($outcome, $profile->last_attempt_status);
        $this->assertSame(120_000, $profile->likes_count);
        $this->assertNull($profile->refresh_queued_at);
    }

    public function test_an_invalid_response_is_not_retried_and_keeps_data(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response($this->fixture('negative-likes.json'))]);

        $job = $this->runRefresh($profile);

        $this->assertFalse($job->job->isReleased());
        $profile->refresh();
        $this->assertSame(120_000, $profile->likes_count);
        $this->assertSame('invalid_response', $profile->last_attempt_status);
        $this->assertSame('invalid response: profile.likes is negative: -5', $profile->last_failure_reason);
    }

    public function test_explicit_zero_likes_is_stored(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response($this->fixture('zero-likes.json'))]);

        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(0, $profile->likes_count);
        $this->assertSame(12, $profile->revision);
        $this->assertEqualsWithDelta(now()->addHours(72)->timestamp, $profile->next_refresh_at->timestamp, 5);
    }

    public function test_exactly_100000_likes_is_refreshed_every_72_hours(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response(['profile' => ['likes' => 100_000], 'revision' => 11])]);

        $this->runRefresh($profile);

        $this->assertEqualsWithDelta(now()->addHours(72)->timestamp, $profile->refresh()->next_refresh_at->timestamp, 5);
    }

    public function test_the_same_revision_again_confirms_the_data_without_rewriting_it(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response(['profile' => ['likes' => 120_000, 'name' => 'After'], 'revision' => 10])]);

        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame('unchanged', $profile->last_attempt_status);
        $this->assertSame('Before', $profile->name, 'same revision: nothing is rewritten');
        $this->assertTrue($profile->last_success_at->isToday(), 'but the data is confirmed current');
    }

    public function test_a_late_older_response_is_recorded_as_stale(): void
    {
        $profile = $this->profileWithValidData(attributes: ['revision' => 11, 'likes_count' => 121_000]);
        $lastSuccess = $profile->last_success_at;
        Http::fake(['*' => Http::response($this->fixture('old-format.json'))]);

        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(121_000, $profile->likes_count);
        $this->assertSame('stale_ignored', $profile->last_attempt_status);
        $this->assertEquals($lastSuccess, $profile->last_success_at);
        $this->assertSame('stale_ignored', RefreshAttempt::query()->sole()->outcome);
    }

    public function test_last_attempt_success_and_failure_stay_distinguishable(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::sequence()
            ->push($this->fixture('new-format.json'))
            ->push('', 500)]);

        $this->runRefresh($profile);
        $this->travel(5)->minutes();
        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(121_000, $profile->likes_count);
        $this->assertSame('server_error', $profile->last_attempt_status);
        $this->assertTrue($profile->last_attempt_at->gt($profile->last_success_at));
        $this->assertEquals($profile->last_attempt_at, $profile->last_failure_at);
        $this->assertSame('HTTP 500 with an empty body', $profile->last_failure_reason);
        $this->assertSame(0, $profile->consecutive_failures, 'a retry is pending: not a failed refresh yet');
    }

    public function test_a_redelivered_job_with_no_attempts_left_does_not_call_the_upstream(): void
    {
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        $this->previousAttempts($profile, $refreshId, 'rate_limited', 8);
        $this->previousAttempts($profile, $refreshId, 'timeout', 2);
        Http::fake();

        $this->runRefresh($profile, $refreshId);

        Http::assertNothingSent();
        $this->assertSame(10, RefreshAttempt::query()->count());
        $profile->refresh();
        $this->assertSame('gave_up', $profile->last_attempt_status);
        $this->assertNull($profile->pending_refresh_id);
        $this->assertSame(120_000, $profile->likes_count);
    }

    public static function writesWithTheirAttempt(): array
    {
        return [
            'a successful write' => [200],
            'a recorded failure' => [500],
        ];
    }

    #[DataProvider('writesWithTheirAttempt')]
    public function test_a_profile_write_and_its_attempt_row_commit_together(int $status): void
    {
        // Review finding: the profile update and the attempt row were separate commits.
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        Http::fake(['*' => $status === 200 ? Http::response($this->fixture('new-format.json')) : Http::response('', 500)]);
        // The attempt row is written before the call; recording its result, inside
        // the transaction with the profile write, is what fails here.
        RefreshAttempt::updating(fn () => throw new RuntimeException('database went away'));

        try {
            $this->runRefresh($profile, $refreshId);
            $this->fail('the job should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('database went away', $e->getMessage());
        }

        $profile->refresh();
        $this->assertSame([120_000, 10], [$profile->likes_count, $profile->revision], 'nothing written without its attempt result');
        $this->assertSame('success', $profile->last_attempt_status);
        $this->assertNull($profile->last_failure_at);
        $this->assertSame($refreshId, $profile->pending_refresh_id, 'the claim stays for the redelivery');
        $this->assertSame(['in_flight'], RefreshAttempt::query()->pluck('outcome')->all(), 'the call was made, so it is counted');

        // The redelivery counts the unanswered call and makes the next attempt.
        RefreshAttempt::flushEventListeners();
        $this->runRefresh($profile, $refreshId);
        $this->assertSame(['in_flight', $status === 200 ? 'success' : 'server_error'], RefreshAttempt::query()->orderBy('id')->pluck('outcome')->all());
    }

    public function test_the_same_revision_with_different_likes_corrects_the_stored_row(): void
    {
        // What the old handler left: 0 stored with the upstream's real revision.
        $profile = $this->profileWithValidData(attributes: ['likes_count' => 0, 'revision' => 11]);
        Http::fake(['*' => Http::response($this->fixture('new-format.json'))]);

        $this->runRefresh($profile);

        $this->assertSame(121_000, $profile->refresh()->likes_count);
        $this->assertSame('success', $profile->last_attempt_status);
    }

    public function test_a_replayed_old_job_does_not_release_a_newer_claim(): void
    {
        $profile = $this->profileWithValidData();
        $old = (string) Str::uuid();
        Http::fake(['*' => Http::response($this->fixture('new-format.json'))]);
        $this->runRefresh($profile, $old);

        // A new refresh is queued, then the old job is delivered once more
        // (its worker died before acknowledging it).
        $new = (string) Str::uuid();
        Profile::query()->whereKey($profile->id)->update(['refresh_queued_at' => now(), 'pending_refresh_id' => $new]);
        $this->deliverAgain($profile, $old);

        $profile->refresh();
        $this->assertSame($new, $profile->pending_refresh_id, 'the newer refresh still owns the claim');
        $this->assertSame(121_000, $profile->likes_count);
        $this->assertSame(1, RefreshAttempt::query()->count(), 'the old job is skipped');
        Http::assertSentCount(1);
    }

    public function test_a_job_whose_claim_was_taken_over_does_not_call_the_upstream(): void
    {
        $profile = $this->profileWithValidData(attributes: ['refresh_queued_at' => now(), 'pending_refresh_id' => (string) Str::uuid()]);
        Http::fake();

        $this->deliverAgain($profile, (string) Str::uuid());

        Http::assertNothingSent();
        $this->assertSame(0, RefreshAttempt::query()->count());
        $this->assertSame(120_000, $profile->refresh()->likes_count);
    }

    public function test_the_last_429_of_a_refresh_still_pauses_the_account(): void
    {
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        $this->previousAttempts($profile, $refreshId, 'rate_limited', config('refresh.retry.rate_limited.max') - 1);
        Http::fake(['*' => Http::response('', 429)]);

        $job = $this->runRefresh($profile, $refreshId);

        $this->assertFalse($job->job->isReleased());
        $this->assertGreaterThan(0, app(AccountThrottle::class)->cooldownRemainingMs($profile->account_id));
    }

    public function test_repeated_final_failures_back_off_instead_of_retrying_hourly(): void
    {
        $profile = $this->profileWithValidData(attributes: ['consecutive_failures' => 3]);
        Http::fake(['*' => Http::response(['error' => 'Not Found'], 404)]);

        $this->runRefresh($profile);

        // 4th failure in a row: 60 min × 2^3 = 8 hours.
        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, $profile->refresh()->next_refresh_at->timestamp, 5);

        $profile->forceFill(['consecutive_failures' => 20])->save();
        $this->runRefresh($profile);
        $this->assertEqualsWithDelta(now()->addHours(72)->timestamp, $profile->refresh()->next_refresh_at->timestamp, 5, 'capped at the 72 h interval');
    }

    public function test_a_stale_response_does_not_bring_the_next_refresh_forward_or_loop(): void
    {
        // Due now, but the upstream answers with an older revision than stored.
        $due = $this->profileWithValidData(attributes: ['revision' => 11, 'likes_count' => 121_000, 'next_refresh_at' => now()->subMinute()]);
        // Not due for a day; a late stale answer must not make it due sooner.
        $later = $this->profileWithValidData(attributes: ['revision' => 11, 'likes_count' => 121_000, 'next_refresh_at' => now()->addDay()]);
        Http::fake(['*' => Http::response($this->fixture('old-format.json'))]);

        $this->runRefresh($due);
        $this->runRefresh($later);

        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, $due->refresh()->next_refresh_at->timestamp, 5);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $later->refresh()->next_refresh_at->timestamp, 5);
    }

    public function test_giving_up_releases_the_claim_and_keeps_data(): void
    {
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        Profile::query()->whereKey($profile->id)->update(['refresh_queued_at' => now(), 'pending_refresh_id' => $refreshId]);

        $job = app(RefreshScheduler::class)->makeJob($profile->id, $profile->account_id, $refreshId);
        $job->failed(new MaxAttemptsExceededException('App\Jobs\RefreshProfile has been attempted too many times.'));

        $profile->refresh();
        $this->assertSame('gave_up', $profile->last_attempt_status);
        $this->assertNull($profile->refresh_queued_at);
        $this->assertSame(120_000, $profile->likes_count);
    }

    public function test_retry_after_on_a_503_is_honoured(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response('', 503, ['Retry-After' => '9'])]);

        $this->runRefresh($profile)->assertReleased(9);
    }

    public function test_an_old_job_giving_up_does_not_mark_a_newer_refresh_as_failed(): void
    {
        $profile = $this->profileWithValidData();
        $new = (string) Str::uuid();
        Profile::query()->whereKey($profile->id)->update(['refresh_queued_at' => now(), 'pending_refresh_id' => $new]);

        // A job of an older refresh, whose claim was taken over, fails for good.
        app(RefreshScheduler::class)->makeJob($profile->id, $profile->account_id, (string) Str::uuid())
            ->failed(new RuntimeException('worker died'));

        $profile->refresh();
        $this->assertSame('success', $profile->last_attempt_status);
        $this->assertNull($profile->last_failure_at);
        $this->assertSame($new, $profile->pending_refresh_id);
    }

    public function test_an_answer_for_another_profile_is_not_written(): void
    {
        // A misrouted answer, or a username that belongs to someone else now.
        $profile = $this->profileWithValidData(attributes: ['username' => 'madison420ivy', 'upstream_id' => 5_140_520]);
        Http::fake(['*' => Http::sequence()
            ->push($this->fixture('onlyfans-madison420ivy.json'))
            ->push(['id' => 999, 'username' => 'madison420ivy', 'favoritedCount' => 5])
            ->push(['id' => 5_140_520, 'username' => 'someone-else', 'favoritedCount' => 5])]);

        $this->runRefresh($profile);
        $this->assertSame(612_018, $profile->refresh()->likes_count, 'the right profile is written');

        $this->runRefresh($profile);
        $this->runRefresh($profile);

        $this->assertSame(612_018, $profile->refresh()->likes_count);
        $errors = RefreshAttempt::query()->orderBy('id')->pluck('error')->all();
        $this->assertStringContainsString('belongs to another account', $errors[1]);
        $this->assertStringContainsString('the answer is for someone-else', $errors[2]);
    }

    public function test_nothing_is_recorded_when_the_request_cannot_even_be_built(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake();
        // E.g. Redis down: the session cannot be read, so nothing goes out.
        $this->mock(SessionStore::class)->shouldReceive('for')->andThrow(new RuntimeException('Redis went away'));

        try {
            $this->runRefresh($profile);
            $this->fail('the job should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Redis went away', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, RefreshAttempt::query()->count(), 'no attempt: nothing was sent');
    }

    public function test_waiting_for_another_workers_rules_load_spends_no_attempt(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake();
        // Another worker is loading the signing rules and nothing is cached yet.
        $lock = Cache::lock('onlyfans:rules-load:upstream.test', 20);
        $lock->get();

        $job = $this->runRefresh($profile);
        $lock->release();

        $job->assertReleased(3);
        Http::assertNothingSent();
        $this->assertSame(0, RefreshAttempt::query()->count());
        $this->assertNotNull($profile->refresh()->pending_refresh_id);
    }

    public function test_logs_follow_a_refresh(): void
    {
        // The OnlyFans session's secrets stay out of the logs: OnlyFansClientTest.
        $profile = $this->profileWithValidData(attributes: ['username' => 'madison420ivy']);
        $refreshId = (string) Str::uuid();
        Http::fake(['*' => Http::sequence()->push('', 429)->push($this->fixture('new-format.json'))]);

        $this->runRefresh($profile, $refreshId);
        $this->runRefresh($profile, $refreshId);

        $lines = collect($this->refreshLogLines())->where('context.refresh_id', $refreshId)->values();
        $this->assertSame(['refresh.retry_scheduled', 'refresh.success'], $lines->pluck('message')->all());
        $this->assertSame([1, 2], $lines->pluck('context.upstream_attempt')->all());
        foreach ($lines as $line) {
            $this->assertSame($profile->id, $line['context']['profile_id']);
            $this->assertSame($profile->account->slug, $line['context']['account']);
        }
    }

    private function previousAttempts(Profile $profile, string $refreshId, string $outcome, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            RefreshAttempt::query()->create([
                'profile_id' => $profile->id,
                'account_id' => $profile->account_id,
                'refresh_id' => $refreshId,
                'handler' => 'v2',
                'outcome' => $outcome,
                'duration_ms' => 1,
            ]);
        }
    }
}
