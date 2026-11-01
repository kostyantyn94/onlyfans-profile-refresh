<?php

namespace Tests;

use App\Models\Account;
use App\Models\Profile;
use App\Refresh\RefreshScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected string $refreshLog;

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real upstream.
        Http::preventStrayRequests();
        // The legacy handler sleeps between its retries; don't wait for real.
        Sleep::fake();

        // Redis databases 14/15 belong to the tests (phpunit.xml).
        Redis::connection()->flushdb();
        Redis::connection('cache')->flushdb();

        $this->refreshLog = storage_path('logs/refresh-test-'.getmypid().'.log');
        @unlink($this->refreshLog);
        config(['logging.channels.refresh.path' => $this->refreshLog]);
    }

    protected function fixture(string $name): string
    {
        return file_get_contents(base_path('resources/fixtures/upstream/'.$name));
    }

    protected function makeAccount(array $attributes = []): Account
    {
        return Account::query()->create([
            'slug' => 'acct-'.Str::lower(Str::random(6)),
            'name' => 'Test account',
            'base_url' => 'https://upstream.test',
            'api_key' => 'secret-key-'.Str::random(12),
            ...$attributes,
        ]);
    }

    /** The profile from the task: last valid likes 120,000 at revision 10. */
    protected function profileWithValidData(?Account $account = null, array $attributes = []): Profile
    {
        $profile = Profile::query()->create([
            'account_id' => ($account ?? $this->makeAccount())->id,
            'username' => 'creator-'.Str::lower(Str::random(6)),
        ]);

        $profile->forceFill([
            'likes_count' => 120_000,
            'revision' => 10,
            'name' => 'Before',
            'last_attempt_at' => now()->subDays(2),
            'last_attempt_status' => 'success',
            'last_success_at' => now()->subDays(2),
            'next_refresh_at' => now()->subHour(),
            ...$attributes,
        ])->save();

        return $profile->refresh();
    }

    /**
     * Run one refresh job for the profile the way a worker would, with the
     * handler selected by REFRESH_HANDLER (v2 by default, or legacy).
     * Queue interactions are faked so release()/fail() can be asserted.
     */
    protected function runRefresh(Profile $profile, ?string $refreshId = null): ShouldQueue
    {
        $refreshId ??= (string) Str::uuid();

        Profile::query()->whereKey($profile->id)->update([
            'refresh_queued_at' => now(),
            'pending_refresh_id' => $refreshId,
        ]);

        $job = app(RefreshScheduler::class)->makeJob($profile->id, $profile->account_id, $refreshId);
        $job->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        return $job;
    }

    /** Run the job for a refresh again without re-claiming: a redelivery. */
    protected function deliverAgain(Profile $profile, string $refreshId): ShouldQueue
    {
        $job = app(RefreshScheduler::class)->makeJob($profile->id, $profile->account_id, $refreshId);
        $job->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        return $job;
    }

    protected function refreshLogLines(): array
    {
        if (! is_file($this->refreshLog)) {
            return [];
        }

        return array_map(fn ($line) => json_decode($line, true), file($this->refreshLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}
