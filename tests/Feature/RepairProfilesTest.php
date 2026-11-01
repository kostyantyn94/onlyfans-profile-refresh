<?php

namespace Tests\Feature;

use App\Jobs\RefreshProfile;
use App\Models\Profile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RepairProfilesTest extends TestCase
{
    public function test_without_repair_a_profile_the_old_handler_broke_waits_for_its_next_scheduled_refresh(): void
    {
        Queue::fake();
        // The old handler stored 0, called it a success and scheduled the next refresh a day out.
        $this->profileWithValidData(attributes: ['likes_count' => 0, 'revision' => 11, 'last_success_at' => now()->subMinutes(10), 'next_refresh_at' => now()->addDay()]);

        $this->assertSame(0, app(\App\Refresh\RefreshScheduler::class)->enqueueDue(), 'not due: the zero would stay visible for a day');
    }

    public function test_repair_clears_the_revision_and_requeues_profiles_written_during_the_incident(): void
    {
        Queue::fake();
        $corrupted = $this->profileWithValidData(attributes: ['likes_count' => 0, 'revision' => 11, 'last_success_at' => now()->subMinutes(10)]);
        $before = $this->profileWithValidData(attributes: ['last_success_at' => now()->subDays(2)]);

        $this->artisan('profiles:repair', ['--since' => now()->subHour()->toDateTimeString()])
            ->expectsOutputToContain('1 profile(s) marked successful')
            ->assertSuccessful();

        $this->assertNull($corrupted->refresh()->revision);
        $this->assertSame(10, $before->refresh()->revision, 'written before the incident: untouched');
        Queue::assertPushed(RefreshProfile::class, fn ($job) => $job->profileId === $corrupted->id);
        Queue::assertNotPushed(RefreshProfile::class, fn ($job) => $job->profileId === $before->id);

        // The queued refresh can now write the real value.
        Http::fake(['*' => Http::response($this->fixture('new-format.json'))]);
        $this->runRefresh($corrupted->refresh());
        $this->assertSame(121_000, $corrupted->refresh()->likes_count);
        $this->assertSame(11, $corrupted->revision);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Queue::fake();
        $profile = $this->profileWithValidData(attributes: ['last_success_at' => now()]);

        $this->artisan('profiles:repair', ['--since' => now()->subHour()->toDateTimeString(), '--dry-run' => true])->assertSuccessful();

        $this->assertSame(10, $profile->refresh()->revision);
        Queue::assertNothingPushed();
    }
}
