<?php

namespace Tests\Feature;

use App\Jobs\Middleware\AccountGate;
use App\Jobs\RefreshProfile;
use App\Models\Profile;
use App\Refresh\AccountThrottle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/** One busy or rate-limited account must not hold up the others. */
class AccountIsolationTest extends TestCase
{
    public function test_a_cooling_down_account_waits_while_another_account_is_served(): void
    {
        $busy = $this->profileWithValidData();
        $calm = $this->profileWithValidData();
        app(AccountThrottle::class)->coolDown($busy->account_id, 20);
        Http::fake(['*' => Http::response($this->fixture('new-format.json'))]);

        $busyJob = $this->throughGate($busy);
        $calmJob = $this->throughGate($calm);

        $this->assertTrue($busyJob->job->isReleased(), 'the busy account waits for its cooldown');
        $this->assertGreaterThanOrEqual(20, $busyJob->job->releaseDelay);
        $this->assertFalse($calmJob->job->isReleased());
        $this->assertSame(121_000, $calm->refresh()->likes_count, 'the other account is refreshed meanwhile');
        $this->assertSame(120_000, $busy->refresh()->likes_count);
        Http::assertSentCount(1);
    }

    public function test_an_account_cannot_use_more_than_its_concurrency_limit(): void
    {
        $account = $this->makeAccount(['max_concurrency' => 1]);
        $second = $this->profileWithValidData($account);
        $other = $this->profileWithValidData();
        Http::fake(['*' => Http::response($this->fixture('new-format.json'))]);

        // While another job of the account holds its only slot...
        $inside = [];
        Redis::funnel(app(AccountThrottle::class)->funnelKey($account->id))
            ->limit(1)->releaseAfter(60)->block(0)
            ->then(function () use ($second, $other, &$inside) {
                $inside['second'] = $this->throughGate($second);
                $inside['other'] = $this->throughGate($other);
            });

        $this->assertTrue($inside['second']->job->isReleased(), 'no free slot: put back with a short delay');
        $this->assertLessThanOrEqual(3, $inside['second']->job->releaseDelay);
        $this->assertFalse($inside['other']->job->isReleased(), 'another account is not affected');
        $this->assertSame(120_000, $second->refresh()->likes_count);

        // ...and once the slot is free again, the deferred job runs.
        $retried = $this->throughGate($second);
        $this->assertFalse($retried->job->isReleased());
        $this->assertSame(121_000, $second->refresh()->likes_count);
    }

    public function test_deferring_does_not_call_the_upstream_or_spend_a_retry(): void
    {
        $profile = $this->profileWithValidData();
        app(AccountThrottle::class)->coolDown($profile->account_id, 5);
        Http::fake();

        $this->throughGate($profile);

        $this->assertSame(0, $profile->attempts()->count(), 'no upstream call, no attempt recorded');
        Http::assertNothingSent();
    }

    public function test_a_cooldown_is_extended_but_never_shortened(): void
    {
        $throttle = app(AccountThrottle::class);

        $throttle->coolDown(42, 30);
        $throttle->coolDown(42, 5);

        $this->assertGreaterThan(25_000, $throttle->cooldownRemainingMs(42));
    }

    private function throughGate(Profile $profile): RefreshProfile
    {
        $refreshId = (string) Str::uuid();
        Profile::query()->whereKey($profile->id)->update(['refresh_queued_at' => now(), 'pending_refresh_id' => $refreshId]);

        $job = (new RefreshProfile($profile->id, $profile->account_id, $refreshId))->withFakeQueueInteractions();

        (new AccountGate($profile->account_id))->handle($job, fn ($job) => app()->call([$job, 'handle']));

        return $job;
    }
}
