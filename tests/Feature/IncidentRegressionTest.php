<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\RefreshAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The incident, as tests. Every profile starts with the task's last valid
 * data: likes 120,000 at revision 10.
 *
 * These run against the handler chosen by REFRESH_HANDLER:
 *   php artisan test --group=incident                         (v2: passes)
 *   REFRESH_HANDLER=legacy php artisan test --group=incident  (legacy: fails)
 * The legacy output is recorded in docs/evidence/.
 */
#[Group('incident')]
class IncidentRegressionTest extends TestCase
{
    public function test_old_format_is_still_accepted(): void
    {
        $profile = $this->profileWithValidData(attributes: ['likes_count' => null, 'revision' => null]);
        Http::fake(['*' => Http::response($this->fixture('old-format.json'))]);

        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(120_000, $profile->likes_count);
        $this->assertSame(10, $profile->revision);
        $this->assertSame('success', $profile->last_attempt_status);
    }

    public function test_new_format_with_likes_inside_profile_is_stored(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response($this->fixture('new-format.json'))]);

        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(121_000, $profile->likes_count, 'likes moved into "profile" and must still be read');
        $this->assertSame(11, $profile->revision);
        $this->assertTrue($profile->last_success_at->isToday());
    }

    public function test_rate_limit_without_retry_after_keeps_data_and_is_retried_later(): void
    {
        $profile = $this->profileWithValidData();
        $lastSuccess = $profile->last_success_at;
        Http::fake(['*' => Http::response($this->fixture('rate-limited.json'), 429)]);

        $job = $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(120_000, $profile->likes_count, 'a 429 must not erase the last valid likes');
        $this->assertSame(10, $profile->revision);
        $this->assertEquals($lastSuccess, $profile->last_success_at, 'a 429 is not a successful refresh');
        $this->assertSame('rate_limited', $profile->last_attempt_status);
        $this->assertNotNull($profile->last_failure_at);
        $this->assertTrue($job->job->isReleased(), 'the refresh must be retried later, not dropped');
        $this->assertGreaterThanOrEqual(1, $job->job->releaseDelay, 'retry after a delay, not at once');
    }

    public function test_server_error_with_empty_body_keeps_data_and_is_retried_later(): void
    {
        $profile = $this->profileWithValidData();
        $lastSuccess = $profile->last_success_at;
        Http::fake(['*' => Http::response('', 500)]);

        $job = $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(120_000, $profile->likes_count, 'an empty 500 must not erase the last valid likes');
        $this->assertSame(10, $profile->revision);
        $this->assertEquals($lastSuccess, $profile->last_success_at);
        $this->assertSame('server_error', $profile->last_attempt_status);
        $this->assertTrue($job->job->isReleased());
    }

    public function test_missing_likes_is_invalid_not_zero(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::response($this->fixture('missing-likes.json'))]);

        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(120_000, $profile->likes_count, 'a missing likes field must not become 0');
        $this->assertSame(10, $profile->revision);
        $this->assertSame('invalid_response', $profile->last_attempt_status);
        $this->assertStringContainsString('likes is missing', $profile->last_failure_reason);
    }

    public function test_an_older_revision_arriving_last_does_not_overwrite_newer_data(): void
    {
        $profile = $this->profileWithValidData();
        // Two refreshes in flight; the newer answer (revision 11) is processed
        // first and the older one (revision 10) arrives late.
        Http::fake(['*' => Http::sequence()
            ->push($this->fixture('new-format.json'))
            ->push(['likes' => 119_000, 'revision' => 10])]);

        $this->runRefresh($profile);
        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(121_000, $profile->likes_count, 'revision 10 arrived last but must not win');
        $this->assertSame(11, $profile->revision);
    }

    public function test_the_same_job_delivered_twice_neither_duplicates_nor_corrupts(): void
    {
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        Http::fake(['*' => Http::response($this->fixture('new-format.json'))]);

        $this->runRefresh($profile, $refreshId);
        // A second delivery of the same job, e.g. Redis handing it out again
        // after retry_after because the first worker never acknowledged it.
        $this->deliverAgain($profile, $refreshId);

        $this->assertSame(1, Profile::query()->count());
        $profile->refresh();
        $this->assertSame(121_000, $profile->likes_count);
        $this->assertSame(11, $profile->revision);
        $this->assertSame(1, RefreshAttempt::query()->where('refresh_id', $refreshId)->count(), 'the second delivery finds the refresh done');
        Http::assertSentCount(1);
    }
}
