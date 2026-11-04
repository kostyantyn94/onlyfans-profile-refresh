<?php

namespace Tests\Feature;

use App\Upstream\OnlyFans\SigningRulesProvider;
use App\Upstream\OnlyFans\SigningRulesUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\SyntheticWebApp;
use Tests\TestCase;

/** Loading, sharing and rejecting signing rules, as several workers see it. */
class SigningRulesProviderTest extends TestCase
{
    private const BASE = 'https://upstream.test';

    private const FIRST = 'resources/fixtures/upstream/onlyfans-test-rules.json';

    private const NEXT = 'resources/fixtures/upstream/onlyfans-test-rules-rotated.json';

    private const WEB_RULES = [
        'static_param' => 'WebAppStaticParam0123456789abcde',
        'prefix' => '55555',
        'suffix' => 'abcdef55',
        'checksum_indexes' => [0, 2, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 30, 32, 34, 36, 38, 39, 39, 1, 3, 5, 7, 9, 11, 13, 15, 17],
        'checksum_constant' => 444,
    ];

    private SigningRulesProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.onlyfans.rules_sources' => ['upstream.test' => [self::FIRST, self::NEXT]]]);
        $this->provider = app(SigningRulesProvider::class);
    }

    public function test_rejections_from_one_session_do_not_condemn_the_rules(): void
    {
        $rules = $this->provider->for(self::BASE);

        // Two requests in flight on the same distrusted session, both rejected.
        $this->provider->reject(self::BASE, $rules, str_repeat('a', 40));
        $this->provider->reject(self::BASE, $rules, str_repeat('a', 40));

        $this->assertSame('12345', $this->provider->for(self::BASE)->prefix, 'still the first source');
    }

    public function test_rules_rejected_for_two_sessions_give_way_to_the_next_source(): void
    {
        $rules = $this->provider->for(self::BASE);

        $this->provider->reject(self::BASE, $rules, str_repeat('a', 40));
        $this->provider->reject(self::BASE, $rules, str_repeat('b', 40));

        $this->assertSame('67890', $this->provider->for(self::BASE)->prefix);
    }

    public function test_rules_onlyfans_accepted_recently_are_not_blamed_for_rejections(): void
    {
        $rules = $this->provider->for(self::BASE);
        $this->provider->accepted(self::BASE, $rules);

        // Two distrusted sessions: their rejections are the sessions', not the rules'.
        $this->provider->reject(self::BASE, $rules, str_repeat('a', 40));
        $this->provider->reject(self::BASE, $rules, str_repeat('b', 40));
        $this->assertSame('12345', $this->provider->for(self::BASE)->prefix);
        $this->assertTrue(collect($this->refreshLogLines())->firstWhere('message', 'onlyfans.signature_rejected')['context']['rules_proven']);

        // Not accepted for 10 minutes and rejected for two sessions: skipped.
        $this->travel(11)->minutes();
        $this->provider->reject(self::BASE, $rules, str_repeat('c', 40));
        $this->provider->reject(self::BASE, $rules, str_repeat('d', 40));
        $this->assertSame('67890', $this->provider->for(self::BASE)->prefix);
    }

    public function test_a_rejection_reloads_the_rules_once_not_once_per_request_in_flight(): void
    {
        config(['services.onlyfans.rules_reject_limit' => 3]);
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['https://rules.test/rules.json']]]);
        Http::fake(['rules.test/*' => Http::response($this->fixture('onlyfans-test-rules.json'))]);
        $rules = $this->provider->for(self::BASE);
        $sentBeforeReload = now()->getTimestampMs();

        // The first rejection reloads the rules (here: the same ones again).
        $this->travel(2)->seconds();
        $this->provider->reject(self::BASE, $rules, str_repeat('a', 40), null, $sentBeforeReload);
        $this->provider->for(self::BASE);
        Http::assertSentCount(2);

        // Another request sent before that reload is rejected late: no second reload.
        $this->provider->reject(self::BASE, $rules, str_repeat('b', 40), null, $sentBeforeReload);
        $this->provider->for(self::BASE);
        Http::assertSentCount(2);
    }

    public function test_the_only_source_is_never_skipped(): void
    {
        config(['services.onlyfans.rules_sources' => ['upstream.test' => [self::FIRST]]]);
        $rules = $this->provider->for(self::BASE);

        // Two sessions rejected and nothing else to try: refusing to send would stop
        // every refresh for good, so the rules stay in use (and are reloaded).
        $this->provider->reject(self::BASE, $rules, str_repeat('a', 40));
        $this->provider->reject(self::BASE, $rules, str_repeat('b', 40));

        $this->assertSame('12345', $this->provider->for(self::BASE)->prefix);
        $loaded = collect($this->refreshLogLines())->where('message', 'onlyfans.rules_loaded')->last();
        $this->assertTrue($loaded['context']['rejected_recently']);
    }

    public function test_a_newer_release_reloads_the_rules_once_however_many_responses_show_it(): void
    {
        $web = new SyntheticWebApp(self::WEB_RULES);
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['webapp:https://upstream.test/']]]);
        Http::fake([
            'upstream.test/' => Http::response($web->page()),
            SyntheticWebApp::BASE.'app.js' => Http::response($web->app()),
            SyntheticWebApp::BASE.'7777.js' => Http::response($web->chunk()),
        ]);
        $rules = $this->provider->for(self::BASE);

        // The page still serves the old release (a CDN lags); OnlyFans' API already answers from the new one.
        foreach (range(1, 3) as $i) {
            $this->provider->accepted(self::BASE, $rules, '202611050900-0123456789');
            $this->provider->for(self::BASE);
        }

        Http::assertSentCount(4);
    }

    public function test_the_rejection_window_does_not_slide(): void
    {
        config(['services.onlyfans.rules_reject_limit' => 3]);
        $rules = $this->provider->for(self::BASE);
        $this->provider->reject(self::BASE, $rules, str_repeat('a', 40));
        $key = 'onlyfans:rules-rejected:upstream.test:'.$rules->fingerprint().':-';
        Redis::expire($key, 100);

        $this->provider->reject(self::BASE, $rules, str_repeat('b', 40));

        $this->assertLessThanOrEqual(100, Redis::ttl($key), 'a later rejection does not extend the window');
        $this->assertSame(2, (int) Redis::scard($key));
    }

    public function test_a_response_from_a_newer_web_app_release_reloads_the_rules(): void
    {
        $web = new SyntheticWebApp(self::WEB_RULES);
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['webapp:https://upstream.test/']]]);
        Http::fake([
            'upstream.test/' => Http::response($web->page()),
            SyntheticWebApp::BASE.'app.js' => Http::response($web->app()),
            SyntheticWebApp::BASE.'7777.js' => Http::response($web->chunk()),
        ]);
        $rules = $this->provider->for(self::BASE);
        Http::assertSentCount(3);

        // Same release, or an older one: nothing to do.
        $this->provider->accepted(self::BASE, $rules, SyntheticWebApp::RELEASE);
        $this->provider->accepted(self::BASE, $rules, '202601010000-0000000000');
        $this->provider->for(self::BASE);
        Http::assertSentCount(3);

        // OnlyFans answers from a newer release: the next request reloads (here
        // the page still names the old release, so only the page is read).
        $this->provider->accepted(self::BASE, $rules, '202611050900-0123456789');
        $this->provider->for(self::BASE);
        Http::assertSentCount(4);
    }

    public function test_a_failed_load_is_remembered_instead_of_asking_the_sources_again(): void
    {
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['https://rules.test/rules.json']]]);
        Http::fake(['rules.test/*' => Http::response('', 503)]);

        foreach (range(1, 3) as $i) {
            try {
                $this->provider->for(self::BASE);
                $this->fail('no usable rules');
            } catch (SigningRulesUnavailable $e) {
                $this->assertStringContainsString('HTTP 503', $e->getMessage());
            }
        }

        Http::assertSentCount(1);
    }

    public function test_stale_rules_are_used_while_another_worker_reloads_them(): void
    {
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['https://rules.test/rules.json']]]);
        Http::fake(['rules.test/*' => Http::response($this->fixture('onlyfans-test-rules.json'))]);
        $this->provider->for(self::BASE);
        $this->travel(61)->minutes();

        // Another worker holds the load lock: no waiting, no fetching, the last good rules.
        $lock = Cache::lock('onlyfans:rules-load:upstream.test', 20);
        $lock->get();
        $this->assertSame('12345', $this->provider->for(self::BASE)->prefix);
        Http::assertSentCount(1);
        $lock->release();

        // Free lock: this worker reloads.
        $this->provider->for(self::BASE);
        Http::assertSentCount(2);
    }

    public function test_rules_are_read_from_the_web_app_and_kept_per_release(): void
    {
        $web = new SyntheticWebApp(self::WEB_RULES, 5);
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['webapp:https://upstream.test/', self::FIRST]]]);
        Http::fake([
            'upstream.test/' => Http::response($web->page()),
            SyntheticWebApp::BASE.'app.js' => Http::response($web->app()),
            SyntheticWebApp::BASE.'7777.js' => Http::response($web->chunk()),
        ]);

        $rules = $this->provider->for(self::BASE);

        $this->assertSame(['55555', self::WEB_RULES['static_param'], SyntheticWebApp::RELEASE], [$rules->prefix, $rules->staticParam, $rules->release]);
        $this->assertSame('webapp:https://upstream.test/', $rules->source);
        Http::assertSentCount(3);

        // An hour later, same release: only the page is read again.
        $this->travel(61)->minutes();
        $this->provider->for(self::BASE);
        Http::assertSentCount(4);

        $reads = collect($this->refreshLogLines())->where('message', 'onlyfans.webapp_rules_read');
        $this->assertCount(1, $reads, 'the code was read once');
        $this->assertSame(['7777.js', '807777'], [$reads->first()['context']['signing_script'], $reads->first()['context']['signing_module']]);
    }

    public function test_when_the_web_app_cannot_be_read_the_next_source_is_used(): void
    {
        $web = new SyntheticWebApp(self::WEB_RULES);
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['webapp:https://upstream.test/', self::FIRST]]]);
        Http::fake([
            'upstream.test/' => Http::response($web->page()),
            SyntheticWebApp::BASE.'app.js' => Http::response($web->app()),
            // A release whose signing code looks different.
            SyntheticWebApp::BASE.'7777.js' => Http::response('/* 807777:function( something else */'),
        ]);

        $this->assertSame('12345', $this->provider->for(self::BASE)->prefix, 'the fixture file, next in line');
        $loaded = collect($this->refreshLogLines())->firstWhere('message', 'onlyfans.rules_loaded');
        $this->assertSame(self::FIRST, $loaded['context']['rules_source']);
        $this->assertStringStartsWith('webapp:https://upstream.test/: ', $loaded['context']['passed_over'][0], 'why the web app was passed over');
    }

    public function test_a_release_that_cannot_be_read_is_not_downloaded_again_on_every_reload(): void
    {
        $web = new SyntheticWebApp(self::WEB_RULES);
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['webapp:https://upstream.test/', self::FIRST]]]);
        Http::fake([
            'upstream.test/' => Http::response($web->page()),
            SyntheticWebApp::BASE.'app.js' => Http::response($web->app()),
            SyntheticWebApp::BASE.'7777.js' => Http::response('/* 807777:function( something else */'),
        ]);
        $this->provider->for(self::BASE);
        Http::assertSentCount(3);

        // Reloaded soon after (e.g. after a rejection): only the page is read,
        // the release is known to be unreadable.
        $this->assertSame('12345', $this->provider->reloadNow(self::BASE)->prefix);
        Http::assertSentCount(4);

        // Ten minutes later the release's code is read again.
        $this->travel(11)->minutes();
        $this->provider->reloadNow(self::BASE);
        Http::assertSentCount(7);
    }

    public function test_a_download_failure_is_not_taken_for_unreadable_code(): void
    {
        $web = new SyntheticWebApp(self::WEB_RULES);
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['webapp:https://upstream.test/', self::FIRST]]]);
        Http::fake([
            'upstream.test/' => Http::response($web->page()),
            SyntheticWebApp::BASE.'app.js' => Http::sequence()->push('', 503)->push($web->app()),
            SyntheticWebApp::BASE.'7777.js' => Http::response($web->chunk()),
        ]);

        $this->assertSame('12345', $this->provider->for(self::BASE)->prefix, 'a passing 503: the next source for now');

        // Not remembered as an unreadable release: the next load reads the web app.
        $this->assertSame('55555', $this->provider->reloadNow(self::BASE)->prefix);
    }

    public function test_the_rules_in_use_and_their_source_can_be_shown(): void
    {
        // Rules from the fixture file are in use; the web app becomes the first source.
        $this->provider->for(self::BASE);
        $web = new SyntheticWebApp(self::WEB_RULES);
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['webapp:https://upstream.test/', self::FIRST]]]);
        Http::fake([
            'upstream.test/' => Http::response($web->page()),
            SyntheticWebApp::BASE.'app.js' => Http::response($web->app()),
            SyntheticWebApp::BASE.'7777.js' => Http::response($web->chunk()),
        ]);

        $this->artisan('onlyfans:rules', ['--base-url' => self::BASE])
            ->expectsOutputToContain(self::FIRST)
            ->assertSuccessful();
        $this->artisan('onlyfans:rules', ['--base-url' => self::BASE, '--reload' => true])
            ->expectsOutputToContain('webapp:https://upstream.test/')
            ->expectsOutputToContain(SyntheticWebApp::RELEASE)
            ->expectsOutputToContain('55555:<sha1>:<checksum, hex>:abcdef55')
            ->assertSuccessful();
    }

    public function test_the_last_good_rules_stay_in_use_when_the_sources_fail(): void
    {
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['https://rules.test/rules.json']]]);
        Http::fake(['rules.test/*' => Http::sequence()
            ->push($this->fixture('onlyfans-test-rules.json'))
            ->push('', 500)]);
        $this->provider->for(self::BASE);
        $this->travel(61)->minutes();

        $this->assertSame('12345', $this->provider->for(self::BASE)->prefix);
        $this->assertSame('12345', $this->provider->for(self::BASE)->prefix, 'the failure is remembered: no new fetch');
        Http::assertSentCount(2);
        $this->assertContains('onlyfans.rules_reload_failed', array_column($this->refreshLogLines(), 'message'));
    }
}
