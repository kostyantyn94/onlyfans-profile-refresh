<?php

namespace Tests\Feature;

use App\Jobs\LegacyRefreshProfile;
use App\Jobs\RefreshProfile;
use App\Models\Profile;
use App\Refresh\RefreshScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefreshSchedulingTest extends TestCase
{
    public function test_due_profiles_are_queued_once(): void
    {
        Queue::fake();
        $due = $this->profileWithValidData(attributes: ['next_refresh_at' => now()->subMinute()]);
        $never = $this->profileWithValidData(attributes: ['next_refresh_at' => null]);
        $later = $this->profileWithValidData(attributes: ['next_refresh_at' => now()->addHour()]);

        $first = app(RefreshScheduler::class)->enqueueDue();
        $second = app(RefreshScheduler::class)->enqueueDue();

        $this->assertSame(2, $first);
        $this->assertSame(0, $second, 'already pending: not queued again');
        Queue::assertPushed(RefreshProfile::class, 2);
        Queue::assertPushed(RefreshProfile::class, fn ($job) => $job->profileId === $due->id
            && $job->refreshId === $due->refresh()->pending_refresh_id);
        Queue::assertPushed(RefreshProfile::class, fn ($job) => $job->profileId === $never->id);
        Queue::assertNotPushed(RefreshProfile::class, fn ($job) => $job->profileId === $later->id);
        Queue::assertPushedOn('refresh', RefreshProfile::class);
    }

    public function test_a_manual_refresh_is_refused_while_one_is_pending(): void
    {
        Queue::fake();
        $profile = $this->profileWithValidData();
        $scheduler = app(RefreshScheduler::class);

        $this->assertNotNull($scheduler->enqueue($profile));
        $this->assertNull($scheduler->enqueue($profile));

        Queue::assertPushed(RefreshProfile::class, 1);
    }

    public function test_a_lost_claim_expires_and_is_queued_again(): void
    {
        Queue::fake();
        $profile = $this->profileWithValidData(attributes: [
            'refresh_queued_at' => now()->subMinutes(config('refresh.claim_expires_after_minutes') + 1),
            'pending_refresh_id' => '00000000-0000-0000-0000-000000000000',
            'next_refresh_at' => now()->addDay(),
        ]);

        $this->assertSame(1, app(RefreshScheduler::class)->enqueueDue());

        $this->assertNotSame('00000000-0000-0000-0000-000000000000', $profile->refresh()->pending_refresh_id);
    }

    public function test_batches_cover_every_due_profile(): void
    {
        Queue::fake();
        $account = $this->makeAccount();
        foreach (range(1, 7) as $i) {
            $this->profileWithValidData($account);
        }

        $this->assertSame(7, app(RefreshScheduler::class)->enqueueDue(batch: 3));
        $this->assertSame(0, Profile::query()->whereNull('refresh_queued_at')->count());
        Queue::assertPushed(RefreshProfile::class, 7);
    }

    public function test_the_legacy_handler_can_be_selected_for_reproduction(): void
    {
        Queue::fake();
        config(['refresh.handler' => 'legacy']);

        app(RefreshScheduler::class)->enqueue($this->profileWithValidData());

        Queue::assertPushed(LegacyRefreshProfile::class);
    }

    // Sequential, not a real race: createOrFirst returns the existing row, and the
    // unique index is what stops a concurrent second insert.
    public function test_one_profile_per_account_and_username(): void
    {
        $account = $this->makeAccount();

        $a = Profile::query()->createOrFirst(['account_id' => $account->id, 'username' => 'madison420ivy']);
        $b = Profile::query()->createOrFirst(['account_id' => $account->id, 'username' => 'madison420ivy']);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Profile::query()->count());

        $this->expectException(UniqueConstraintViolationException::class);
        Profile::query()->insert(['account_id' => $account->id, 'username' => 'madison420ivy']);
    }

    public function test_the_fetch_command_tracks_and_refreshes_a_profile(): void
    {
        // Shortly after the fixture was cached by Fans API (08:12:09 UTC), so
        // the cached answer is recent enough to be a refresh.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:30:00 UTC'));
        $this->seed();
        config(['refresh.handler' => 'v2']);
        Http::fake(['*' => Http::response($this->fixture('fansapi-madison420ivy.json'))]);

        $this->artisan('profiles:fetch', ['username' => '@MADISON420IVY'])
            ->expectsOutputToContain('Madison Ivy')
            ->assertSuccessful();

        $this->assertSame(1, Profile::query()->count(), 'the seeded profile is reused, not duplicated');
        $this->assertSame(611_905, Profile::query()->sole()->likes_count);
    }
}
