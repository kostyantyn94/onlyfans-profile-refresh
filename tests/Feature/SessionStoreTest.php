<?php

namespace Tests\Feature;

use App\Upstream\OnlyFans\SessionStore;
use Illuminate\Support\Facades\Cache;
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

    public function test_a_late_rejection_of_an_old_session_does_not_drop_the_new_one(): void
    {
        $sessions = app(SessionStore::class);
        $account = $this->makeAccount();
        $old = $sessions->for($account);

        // Worker A's rejection dropped the session and a new one was started;
        // worker B's rejection, of a request sent with the old one, arrives now.
        $sessions->forget($account, $old);
        $new = $sessions->for($account);
        $sessions->forget($account, $old);

        $this->assertSame($new->xBc, $sessions->for($account)->xBc);
    }

    public function test_expired_cookies_are_not_sent(): void
    {
        $sessions = app(SessionStore::class);
        $account = $this->makeAccount();
        $sessions->update($account, $sessions->for($account), [
            'sess=abc; path=/; HttpOnly',
            '__cf_bm=xyz; path=/; Max-Age=1800',
            '_cfuvid=uvw; path=/; expires='.gmdate('D, d M Y H:i:s', now()->addHours(2)->timestamp).' GMT',
        ]);

        $this->assertSame('sess=abc; __cf_bm=xyz; _cfuvid=uvw', $sessions->for($account)->cookieHeader());

        $this->travel(31)->minutes();
        $this->assertSame('sess=abc; _cfuvid=uvw', $sessions->for($account)->cookieHeader(), '__cf_bm lives 30 minutes');

        $this->travel(2)->hours();
        $this->assertSame('sess=abc', $sessions->for($account)->cookieHeader());
    }

    public function test_an_unreadable_stored_session_is_replaced(): void
    {
        $sessions = app(SessionStore::class);
        $account = $this->makeAccount();
        // E.g. encrypted with an APP_KEY that has since been rotated.
        Cache::put('onlyfans:session:'.$account->id, 'eyJpdiI6IjEyMyJ9', now()->addDay());

        $first = $sessions->for($account);

        $this->assertSame($first->xBc, $sessions->for($account)->xBc, 'the new session is stored and shared');
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
