<?php

namespace Tests\Feature;

use App\Upstream\OnlyFans\SigningRulesProvider;
use App\Upstream\OnlyFans\SigningRulesUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Loading, sharing and rejecting signing rules, as several workers see it. */
class SigningRulesProviderTest extends TestCase
{
    private const BASE = 'https://upstream.test';

    private const FIRST = 'resources/fixtures/upstream/onlyfans-test-rules.json';

    private const NEXT = 'resources/fixtures/upstream/onlyfans-test-rules-rotated.json';

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
