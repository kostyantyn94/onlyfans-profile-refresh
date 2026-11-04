<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\AccountThrottle;
use App\Refresh\RefreshScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * The other tests call handle() directly. These push the job through Redis and
 * run it with a real `queue:work --once`, so the middleware (account gate,
 * per-profile lock), release() with a delay and Redis's delayed set are the
 * real ones.
 */
class RealWorkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'redis', 'refresh.handler' => 'v2']);
    }

    public function test_a_failed_attempt_is_released_with_a_delay_and_retried_by_the_worker(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::sequence()->push('', 500)->push($this->fixture('new-format.json'))]);

        $refreshId = app(RefreshScheduler::class)->enqueue($profile);
        $this->work();

        $this->assertSame('server_error', $profile->refresh()->last_attempt_status);
        $this->assertSame(120_000, $profile->likes_count);
        $this->assertSame(1, Redis::zcard('queues:refresh:delayed'), 'released into the delayed set, not lost');
        $this->assertSame(0, Redis::llen('queues:refresh'), 'and not available again at once');

        $this->work();
        Http::assertSentCount(1);

        // Redis moves delayed jobs back by the application clock.
        $this->travel(2)->minutes();
        $this->work();

        $profile->refresh();
        $this->assertSame(121_000, $profile->likes_count);
        $this->assertNull($profile->pending_refresh_id);
        $this->assertSame(['server_error', 'success'], RefreshAttempt::query()->where('refresh_id', $refreshId)->orderBy('id')->pluck('outcome')->all());
        $this->assertSame([1, 2], RefreshAttempt::query()->where('refresh_id', $refreshId)->orderBy('id')->pluck('job_attempt')->all());
    }

    public function test_the_account_gate_defers_a_job_in_a_real_worker_without_calling_the_upstream(): void
    {
        $profile = $this->profileWithValidData();
        app(AccountThrottle::class)->coolDown($profile->account_id, 30);
        Http::fake();

        app(RefreshScheduler::class)->enqueue($profile);
        $this->work();

        Http::assertNothingSent();
        $this->assertSame(1, Redis::zcard('queues:refresh:delayed'));
        $this->assertSame(0, RefreshAttempt::query()->count());
        $this->assertNotNull($profile->refresh()->pending_refresh_id, 'still claimed: the refresh is waiting, not dropped');
    }

    public function test_a_job_that_keeps_throwing_gives_up_after_max_exceptions(): void
    {
        $profile = $this->profileWithValidData();
        // Not an upstream failure (those are handled): a bug or a database outage.
        Http::fake(fn () => throw new \RuntimeException('unexpected'));

        app(RefreshScheduler::class)->enqueue($profile);
        $this->work();
        $this->work();
        $this->work();

        $profile->refresh();
        $this->assertSame('gave_up', $profile->last_attempt_status);
        $this->assertNull($profile->pending_refresh_id, 'the claim is given back');
        $this->assertSame(120_000, $profile->likes_count);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_queued_job_replaced_after_its_claim_expired_stops_without_calling_the_upstream(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response($this->fixture('new-format.json'))]);

        $old = app(RefreshScheduler::class)->enqueue($profile);
        // The queue was so backed up that the claim expired and the scheduler queued a new refresh.
        Profile::query()->whereKey($profile->id)->update(['refresh_queued_at' => now()->subMinutes(config('refresh.claim_expires_after_minutes') + 1)]);
        $this->assertSame(1, app(RefreshScheduler::class)->enqueueDue());
        $new = $profile->refresh()->pending_refresh_id;

        $this->work();
        Http::assertNothingSent();

        $this->work();
        Http::assertSentCount(1);
        $this->assertNotSame($old, $new);
        $this->assertSame([$new], RefreshAttempt::query()->pluck('refresh_id')->all());
        $this->assertSame(121_000, $profile->refresh()->likes_count);
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'refresh', '--once' => true, '--sleep' => 0])
            ->assertSuccessful();
    }
}
