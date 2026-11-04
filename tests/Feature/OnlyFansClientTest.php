<?php

namespace Tests\Feature;

use App\Models\RefreshAttempt;
use App\Upstream\OnlyFans\RequestSigner;
use App\Upstream\OnlyFans\SigningRules;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The signed OnlyFans client, driven through the refresh job. */
class OnlyFansClientTest extends TestCase
{
    // What OnlyFans answers to a signature it does not accept.
    private const REJECTED = ['error' => ['code' => 401, 'message' => 'Please refresh the page']];

    private const STALE_RULES = 'resources/fixtures/upstream/onlyfans-test-rules.json';

    private const NEW_RULES = 'resources/fixtures/upstream/onlyfans-test-rules-rotated.json';

    protected function setUp(): void
    {
        parent::setUp();
        config(['refresh.handler' => 'v2']);
    }

    public function test_the_request_is_signed_like_the_web_app_does_it(): void
    {
        $profile = $this->profileWithValidData(attributes: ['likes_count' => null, 'revision' => null]);
        Http::fake(['upstream.test/api2/v2/users/*' => Http::response($this->user())]);

        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame(612_018, $profile->likes_count);
        $this->assertSame('success', $profile->last_attempt_status);

        Http::assertSent(function (Request $request) use ($profile) {
            $path = '/api2/v2/users/'.$profile->username;
            $time = (int) $request->header('time')[0];

            return $request->url() === 'https://upstream.test'.$path
                && $request->header('sign')[0] === (new RequestSigner)->sign($this->rules(self::STALE_RULES), $path, $time, '0')
                && abs($time - now()->getTimestampMs()) < 60_000
                && $request->header('app-token')[0] === '0123456789abcdef0123456789abcdef'
                && $request->header('user-id')[0] === '0'
                && preg_match('/^[a-f0-9]{40}$/', $request->header('x-bc')[0]) === 1
                && $request->header('User-Agent')[0] === config('services.onlyfans.user_agent')
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_the_guest_session_keeps_its_x_bc_and_the_cookies_onlyfans_sets(): void
    {
        $account = $this->makeAccount();
        [$first, $second] = [$this->profileWithValidData($account), $this->profileWithValidData($account)];
        // As OnlyFans does it: delete sess, set a new one (no Domain attribute), add __cf_bm; later delete __cf_bm.
        Http::fake(['*' => Http::sequence()
            ->push($this->user(), 200, ['Set-Cookie' => [
                'sess=old; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/; domain=.onlyfans.com',
                'sess=abc123; Max-Age=864000; path=/; secure; HttpOnly',
                '__cf_bm=cfbm1; HttpOnly; Path=/',
            ]])
            ->push($this->user(), 200, ['Set-Cookie' => ['__cf_bm=gone; Max-Age=0; Path=/']])
            ->push($this->user())]);

        $this->runRefresh($first);
        $this->runRefresh($second);
        $this->runRefresh($first);

        $requests = $this->sentRequests();
        $this->assertFalse($requests[0]->hasHeader('Cookie'), 'a new guest session has no cookies yet');
        $this->assertSame('sess=abc123; __cf_bm=cfbm1', $requests[1]->header('Cookie')[0]);
        $this->assertSame('sess=abc123', $requests[2]->header('Cookie')[0], 'a deleted cookie is not sent again');
        $this->assertCount(1, array_unique(array_map(fn (Request $r) => $r->header('x-bc')[0], $requests)), 'one x-bc for the session');
    }

    public function test_a_rejected_signature_renews_the_session_and_moves_to_the_next_rules_source(): void
    {
        config(['services.onlyfans.rules_sources' => ['upstream.test' => [self::STALE_RULES, self::NEW_RULES]]]);
        $profile = $this->profileWithValidData();
        $refreshId = (string) Str::uuid();
        // OnlyFans accepts only the rotated rules (prefix 67890).
        Http::fake(fn (Request $request) => str_starts_with($request->header('sign')[0], '67890:')
            ? Http::response($this->user())
            : Http::response(self::REJECTED, 400, ['Set-Cookie' => 'sess=s'.Str::random(6).'; path=/']));

        $first = $this->runRefresh($profile, $refreshId);
        $this->deliverAgain($profile, $refreshId);
        $this->deliverAgain($profile, $refreshId);

        $this->assertTrue($first->job->isReleased(), 'a rejected signature is retried');
        $this->assertSame(['signature_rejected', 'signature_rejected', 'success'], RefreshAttempt::query()->orderBy('id')->pluck('outcome')->all());
        $this->assertSame(612_018, $profile->refresh()->likes_count);

        $requests = $this->sentRequests();
        $this->assertCount(3, $requests);
        // The first rejection may be the session's fault: same rules again, new session.
        $this->assertSame('12345', strtok($requests[1]->header('sign')[0], ':'));
        // Rejected twice, each time with a new session: the next source's rules.
        $this->assertSame('67890', strtok($requests[2]->header('sign')[0], ':'));
        $this->assertFalse($requests[2]->hasHeader('user-id'), 'the rotated rules say the web app no longer sends user-id');
        $this->assertCount(3, array_unique(array_map(fn (Request $r) => $r->header('x-bc')[0], $requests)), 'a new session after every rejection');
        $this->assertFalse($requests[1]->hasHeader('Cookie'), 'the rejected session\'s cookies are dropped');
    }

    public function test_without_usable_rules_nothing_is_sent_and_the_refresh_waits(): void
    {
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['resources/fixtures/upstream/missing.json', 'resources/fixtures/upstream/new-format.json']]]);
        $profile = $this->profileWithValidData();
        Http::fake();

        $job = $this->runRefresh($profile);

        Http::assertNothingSent();
        $profile->refresh();
        $this->assertSame('rules_unavailable', $profile->last_attempt_status);
        $this->assertStringContainsString('file not found', $profile->last_failure_reason);
        $this->assertStringContainsString('missing or invalid: static_param', $profile->last_failure_reason);
        $this->assertSame(120_000, $profile->likes_count);
        $this->assertTrue($job->job->isReleased());
    }

    public function test_rules_are_loaded_once_and_shared(): void
    {
        config(['services.onlyfans.rules_sources' => ['upstream.test' => ['https://rules.test/rules.json']]]);
        Http::fake([
            'rules.test/*' => Http::response($this->fixture('onlyfans-test-rules.json')),
            'upstream.test/*' => Http::response($this->user()),
        ]);

        foreach (range(1, 3) as $i) {
            $this->runRefresh($this->profileWithValidData());
        }

        Http::assertSentCount(4);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'rules.test')));
    }

    public function test_a_logged_in_session_sends_its_user_id_and_cookies(): void
    {
        $xBc = str_repeat('ab', 20);
        $account = $this->makeAccount(['api_key' => json_encode([
            'user_id' => '123456',
            'cookie' => 'sess=s3cr3t-sess; auth_id=123456',
            'x_bc' => $xBc,
            'user_agent' => 'TestBrowser/1.0',
        ])]);
        $profile = $this->profileWithValidData($account);
        Http::fake(['*' => Http::response($this->user())]);

        $this->runRefresh($profile);

        Http::assertSent(function (Request $request) use ($profile, $xBc) {
            $path = '/api2/v2/users/'.$profile->username;

            return $request->header('user-id')[0] === '123456'
                && $request->header('sign')[0] === (new RequestSigner)->sign($this->rules(self::STALE_RULES), $path, (int) $request->header('time')[0], '123456')
                && $request->header('Cookie')[0] === 'sess=s3cr3t-sess; auth_id=123456'
                && $request->header('x-bc')[0] === $xBc
                && $request->header('User-Agent')[0] === 'TestBrowser/1.0';
        });
    }

    public function test_session_secrets_stay_out_of_logs_and_are_stored_encrypted(): void
    {
        $profile = $this->profileWithValidData();
        Http::fake(['*' => Http::sequence()
            ->push(self::REJECTED, 400, ['Set-Cookie' => 'sess=s3cr3t-guest-1; path=/'])
            ->push($this->user(), 200, ['Set-Cookie' => 'sess=s3cr3t-guest-2; path=/'])]);
        $refreshId = (string) Str::uuid();

        $this->runRefresh($profile, $refreshId);
        $this->deliverAgain($profile, $refreshId);

        $xBcs = array_map(fn (Request $r) => $r->header('x-bc')[0], $this->sentRequests());
        $log = file_get_contents($this->refreshLog);
        $cache = Redis::connection('cache');
        $prefix = (string) config('database.redis.options.prefix');
        $stored = implode("\n", array_map(fn ($key) => (string) $cache->get(Str::after($key, $prefix)), $cache->keys('*')));
        $this->assertStringContainsString('onlyfans:session:', implode(' ', $cache->keys('*')), 'the session is in the cache');

        foreach (['s3cr3t-guest', ...$xBcs] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertStringNotContainsString('static_param', $log);
        $this->assertStringContainsString('onlyfans.signature_rejected', $log);
    }

    public function test_an_onlyfans_user_object_without_likes_is_invalid(): void
    {
        $profile = $this->profileWithValidData();
        $user = $this->user();
        unset($user['favoritedCount']);
        Http::fake(['*' => Http::response($user)]);

        $this->runRefresh($profile);

        $profile->refresh();
        $this->assertSame('invalid_response', $profile->last_attempt_status);
        $this->assertStringContainsString('favoritedCount (likes) is missing', $profile->last_failure_reason);
        $this->assertSame(120_000, $profile->likes_count);
    }

    /** A trimmed OnlyFans api2/v2/users response. */
    private function user(array $fields = []): array
    {
        return ['id' => 5_140_520, 'username' => 'madison420ivy', 'name' => 'Madison Ivy', 'favoritedCount' => 612_018, 'postsCount' => 178, ...$fields];
    }

    private function rules(string $path): SigningRules
    {
        return SigningRules::fromArray(json_decode(file_get_contents(base_path($path)), true));
    }

    /** @return list<Request> */
    private function sentRequests(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->values()->all();
    }
}
