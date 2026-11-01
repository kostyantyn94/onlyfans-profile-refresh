<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\AccountThrottle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Behaviour of the fixed refresh job (RefreshProfile) beyond the incident cases. */
class RefreshJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['refresh.handler' => 'v2']);
    }

    public function test_real_fans_api_response_stores_the_full_profile(): void
    {
        $profile = $this->profileWithValidData(attributes: ['likes_count' => null, 'revision' => null, 'name' => null]);
        Http::fake(['upstream.test/api/profiles/*' => Http::response($this->fixture('fansapi-madison420ivy.json'))]);

        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(611_905, $profile->likes_count);
        $this->assertSame('Madison Ivy', $profile->name);
        $this->assertSame(5_140_520, $profile->upstream_id);
        $this->assertSame([178, 623, 202], [$profile->posts_count, $profile->photos_count, $profile->videos_count]);
        $this->assertCount(87, $profile->raw);
        $this->assertSame('madison420ivy', $profile->raw['username']);
        // Above 100,000 likes: refreshed again in 24 hours.
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $profile->next_refresh_at->timestamp, 5);
        $this->assertNull($profile->refresh_queued_at, 'the claim is released');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.$profile->account->api_key)
            && str_ends_with($request->url(), '/api/profiles/'.$profile->username));
    }

    public function test_the_account_key_falls_back_to_the_environment(): void
    {
        $profile = $this->profileWithValidData($this->makeAccount(['api_key' => null]));
        Http::fake(['*' => Http::response($this->fixture('new-format.json'))]);

        $this->runRefresh($profile);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key-0000'));
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

    public function test_server_errors_give_up_after_three_attempts(): void
    {
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        Http::fake(['*' => Http::response('', 503)]);

        $first = $this->runRefresh($profile, $refreshId);
        $second = $this->runRefresh($profile, $refreshId);
        $third = $this->runRefresh($profile, $refreshId);

        $this->assertTrue($first->job->isReleased());
        $this->assertTrue($second->job->isReleased());
        $this->assertFalse($third->job->isReleased());
        $this->assertSame(3, $profile->refresh()->consecutive_failures);
        $this->assertSame(['server_error', 'server_error', 'server_error'], RefreshAttempt::query()->orderBy('id')->pluck('outcome')->all());
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
        $this->assertSame(1, $profile->consecutive_failures);
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
        $job = app(\App\Refresh\RefreshScheduler::class)->makeJob($profile->id, $profile->account_id, $old)->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $profile->refresh();
        $this->assertSame($new, $profile->pending_refresh_id, 'the newer refresh still owns the claim');
        $this->assertSame(121_000, $profile->likes_count);
        $this->assertSame('unchanged', RefreshAttempt::query()->latest('id')->first()->outcome);
    }

    public function test_giving_up_releases_the_claim_and_keeps_data(): void
    {
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        Profile::query()->whereKey($profile->id)->update(['refresh_queued_at' => now(), 'pending_refresh_id' => $refreshId]);

        $job = app(\App\Refresh\RefreshScheduler::class)->makeJob($profile->id, $profile->account_id, $refreshId);
        $job->failed(new MaxAttemptsExceededException('App\Jobs\RefreshProfile has been attempted too many times.'));

        $profile->refresh();
        $this->assertSame('gave_up', $profile->last_attempt_status);
        $this->assertNull($profile->refresh_queued_at);
        $this->assertSame(120_000, $profile->likes_count);
    }

    public function test_logs_follow_a_refresh_and_never_contain_the_key(): void
    {
        $profile = $this->profileWithValidData();
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

        $log = file_get_contents($this->refreshLog);
        $this->assertStringNotContainsString($profile->account->api_key, $log);
        $this->assertStringNotContainsString('test-key-0000', $log);
        $this->assertStringNotContainsStringIgnoringCase('authorization', $log);
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
