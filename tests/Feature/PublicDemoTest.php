<?php

namespace Tests\Feature;

use App\Jobs\RefreshProfile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicDemoTest extends TestCase
{
    private const PROXIED = ['X-Forwarded-For' => '203.0.113.7'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed();
        config(['demo.read_only_when_proxied' => true, 'demo.public_url' => 'https://demo.example.test:8443']);
    }

    public function test_proxied_visitors_can_read_but_not_track_refresh_or_open_horizon(): void
    {
        $profile = $this->profileWithValidData();

        $this->withHeaders(self::PROXIED)->get('/')
            ->assertOk()
            ->assertSee('Public read-only demo')
            ->assertDontSee('Track a username')
            ->assertSee('https://demo.example.test:8443/css/app.css', false);
        $this->withHeaders(self::PROXIED)->get("/profiles/{$profile->id}")->assertOk()->assertDontSee('Refresh now');

        $this->withHeaders(self::PROXIED)->post('/profiles', ['username' => 'someone'])->assertForbidden();
        $this->withHeaders(self::PROXIED)->post("/profiles/{$profile->id}/refresh")->assertForbidden();
        $this->withHeaders(self::PROXIED)->get('/horizon')->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_pagination_links_use_the_public_https_url(): void
    {
        $account = $this->makeAccount();
        \Illuminate\Support\Facades\DB::table('profiles')->insert(array_map(fn ($i) => [
            'account_id' => $account->id, 'username' => "p-{$i}", 'created_at' => now(), 'updated_at' => now(),
        ], range(1, 60)));

        $page = $this->withHeaders(self::PROXIED)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="https://demo.example.test:8443?page=2"', $page);
        $this->assertStringNotContainsString('http://localhost', $page);
        $this->withHeaders(self::PROXIED)->get('/?page=2')->assertOk()->assertSee('p-');
    }

    public function test_requests_made_on_the_machine_itself_are_unaffected(): void
    {
        $this->get('/')->assertOk()->assertSee('Track a username')->assertDontSee('Public read-only demo');
        $this->post('/profiles', ['username' => 'someone'])->assertRedirect();

        Queue::assertPushed(RefreshProfile::class, 1);
    }

    public function test_off_by_default(): void
    {
        config(['demo.read_only_when_proxied' => false]);

        $this->withHeaders(self::PROXIED)->post('/profiles', ['username' => 'someone'])->assertRedirect();
    }
}
