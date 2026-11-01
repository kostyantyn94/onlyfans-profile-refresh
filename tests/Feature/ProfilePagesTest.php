<?php

namespace Tests\Feature;

use App\Jobs\RefreshProfile;
use App\Models\Profile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProfilePagesTest extends TestCase
{
    public function test_the_list_shows_profiles_and_search_finds_them_case_insensitively(): void
    {
        $profile = $this->profileWithValidData(attributes: ['name' => 'Madison Ivy']);
        $other = $this->profileWithValidData(attributes: ['name' => 'Someone Else']);

        $this->get('/')->assertOk()->assertSee($profile->username)->assertSee($other->username)->assertSee('120,000');

        $this->get('/?q=MADISON')->assertOk()->assertSee($profile->username)->assertDontSee($other->username);
    }

    public function test_the_profile_page_keeps_success_and_failure_apart(): void
    {
        $profile = $this->profileWithValidData(attributes: [
            'last_attempt_status' => 'rate_limited',
            'last_failure_at' => now(),
            'last_failure_reason' => 'HTTP 429',
            'raw' => ['username' => 'x', 'favoritedCount' => 120000],
        ]);

        $this->get("/profiles/{$profile->id}")
            ->assertOk()
            ->assertSee('rate_limited')
            ->assertSee('HTTP 429')
            ->assertSee('Last successful refresh')
            ->assertSee('2 fields');
    }

    public function test_tracking_a_username_creates_one_profile_and_queues_one_refresh(): void
    {
        Queue::fake();
        $this->seed();

        $this->post('/profiles', ['username' => '@Madison420ivy'])->assertRedirect();
        $this->post('/profiles', ['username' => 'madison420ivy'])->assertRedirect();

        $this->assertSame(1, Profile::query()->where('username', 'madison420ivy')->count());
        Queue::assertPushed(RefreshProfile::class, 1);
    }

    public function test_refresh_now_is_refused_while_one_is_pending(): void
    {
        Queue::fake();
        $profile = $this->profileWithValidData();

        $this->from("/profiles/{$profile->id}")->post("/profiles/{$profile->id}/refresh")->assertSessionHas('status', fn ($s) => str_contains($s, 'queued'));
        $this->from("/profiles/{$profile->id}")->post("/profiles/{$profile->id}/refresh")->assertSessionHas('status', 'A refresh is already pending for this profile.');

        Queue::assertPushed(RefreshProfile::class, 1);
    }

    public function test_bad_usernames_are_rejected(): void
    {
        $this->seed();

        $this->post('/profiles', ['username' => 'https://onlyfans.com/x y'])->assertSessionHasErrors('username');
    }
}
