<?php

namespace Tests\Feature;

use App\Upstream\OnlyFans\SessionStore;
use Tests\TestCase;

/** The shared OnlyFans session of an account, as several workers update it. */
class SessionStoreTest extends TestCase
{
    public function test_a_session_dropped_after_a_rejection_is_not_brought_back_by_a_late_response(): void
    {
        $sessions = app(SessionStore::class);
        $account = $this->makeAccount();
        $sentWith = $sessions->for($account);

        // Worker A was rejected and dropped the session; worker B's response,
        // sent with the same session, arrives afterwards.
        $sessions->forget($account);
        $sessions->update($account, $sentWith, ['sess=late; path=/']);

        $this->assertNotSame($sentWith->xBc, $sessions->for($account)->xBc);
        $this->assertSame([], $sessions->for($account)->cookies);
    }

    public function test_concurrent_responses_do_not_undo_each_others_cookies(): void
    {
        $sessions = app(SessionStore::class);
        $account = $this->makeAccount();
        $sentWith = $sessions->for($account);

        // Two responses to requests sent with the same (cookie-less) session.
        $sessions->update($account, $sentWith, ['sess=abc; path=/']);
        $sessions->update($account, $sentWith, ['__cf_bm=xyz; path=/']);

        $this->assertSame(['sess' => 'abc', '__cf_bm' => 'xyz'], $sessions->for($account)->cookies);
    }

    public function test_removing_the_login_returns_the_account_to_a_guest_session(): void
    {
        $sessions = app(SessionStore::class);
        $account = $this->makeAccount(['api_key' => $this->credential()]);
        $this->assertSame('123456', $sessions->for($account)->userId);

        $account->update(['api_key' => null]);

        $session = $sessions->for($account->refresh());
        $this->assertSame('0', $session->userId);
        $this->assertTrue($session->isGuest());
        $this->assertNotSame(str_repeat('ab', 20), $session->xBc);
    }

    public function test_a_logged_in_session_follows_the_cookies_onlyfans_sets(): void
    {
        $sessions = app(SessionStore::class);
        $account = $this->makeAccount(['api_key' => $this->credential()]);
        $sentWith = $sessions->for($account);

        $sessions->update($account, $sentWith, ['sess=rotated; path=/', 'auth_hash=; Max-Age=0; path=/']);

        $this->assertSame(['sess' => 'rotated', 'auth_id' => '123456'], $sessions->for($account)->cookies,
            'a rotated cookie replaces the credential\'s, a deleted one does not come back');
    }

    private function credential(): string
    {
        return json_encode([
            'user_id' => '123456',
            'cookie' => 'sess=s3cr3t; auth_id=123456; auth_hash=h4sh',
            'x_bc' => str_repeat('ab', 20),
            'user_agent' => 'TestBrowser/1.0',
        ]);
    }
}
