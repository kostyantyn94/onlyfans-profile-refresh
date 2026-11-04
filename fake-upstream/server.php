<?php

/**
 * Local stand-in for the upstream, served by `php -S` in its own container
 * (compose service "fake-upstream"). Used by `php artisan workload:run`; the
 * tests use Http::fake instead.
 *
 *   GET /{account}/api2/v2/users/{username}  OnlyFans api2, signed like the real one
 *   GET /rules.json                          the signing rules in force (rules.json,
 *                                            or rules-rotated.json after a rotation)
 *   GET /api/profiles/{username}             Fans API, Bearer key (driver "fansapi")
 *
 * A signed request must carry app-token, time, x-bc and a sign that matches the
 * rules in force; otherwise it gets OnlyFans' answer to a bad signature: HTTP
 * 400, {"error":{"code":401,"message":"Please refresh the page"}}. The workload
 * can rotate the rules mid-run (key fake-upstream:rotate_rules_at, Unix time),
 * as OnlyFans does with a release.
 *
 * Each account behaves as described by a JSON scenario in Redis, written by
 * the workload command (key fake-upstream:scenario:{account}):
 *
 *   format       "old" (top-level likes), "new" (profile.likes), "mixed"
 *                (alternates old/new, like a partial upstream rollout), "onlyfans"
 *                (the api2 user object) or "fansapi"
 *   likes        likes to report (default 121000)
 *   revision     revision to report (default 11)
 *   latency_ms   added to every request
 *   storm_until  Unix time; until then every request gets 429, no Retry-After
 *   per_second   after that, at most this many requests per second, else 429
 *   error_every  every Nth request gets HTTP 500 with an empty body
 *
 * Every answer is counted per account and status (fake-upstream:status:{account}),
 * so the workload can compare what the app believes with what the upstream saw.
 */

declare(strict_types=1);

$redis = new Redis;
$redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379));

function respond(Redis $redis, ?string $account, int $status, ?array $body): never
{
    if ($account !== null) {
        $redis->hIncrBy("fake-upstream:status:{$account}", (string) $status, 1);
    }

    http_response_code($status);

    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body);
    }

    exit;
}

/** The rules in force now: rotated once fake-upstream:rotate_rules_at has passed. */
function rulesInForce(Redis $redis): array
{
    $rotateAt = (float) ($redis->get('fake-upstream:rotate_rules_at') ?: 0);
    $file = $rotateAt > 0 && microtime(true) >= $rotateAt ? 'rules-rotated.json' : 'rules.json';

    return json_decode((string) file_get_contents(__DIR__.'/'.$file), true);
}

/** The same algorithm as App\Upstream\OnlyFans\RequestSigner, written separately. */
function expectedSign(array $rules, string $path, string $time, string $userId): string
{
    $sha1 = sha1($rules['static_param']."\n".$time."\n".$path."\n".$userId);
    $sum = array_sum(array_map(fn (int $i) => ord($sha1[$i]), $rules['checksum_indexes'])) + $rules['checksum_constant'];
    // "54321:{}:{:x}:fa4e0001": "{:x}" has a colon of its own, so first and last part.
    $parts = explode(':', $rules['format']);

    return $parts[0].':'.$sha1.':'.dechex(abs($sum)).':'.end($parts);
}

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url($uri, PHP_URL_PATH);

if ($path === '/health') {
    echo 'ok';
    exit;
}

if ($path === '/rules.json') {
    respond($redis, null, 200, rulesInForce($redis));
}

if (preg_match('#^/([a-z0-9_-]{1,32})/api2/v2/users/([A-Za-z0-9_.-]{1,64})$#', $path, $match)) {
    [, $account, $username] = $match;

    if (! $redis->sIsMember('fake-upstream:accounts', $account)) {
        respond($redis, null, 404, ['error' => ['code' => 0, 'message' => 'Not found']]);
    }

    $rules = rulesInForce($redis);
    $time = $_SERVER['HTTP_TIME'] ?? '';
    $signed = ($_SERVER['HTTP_APP_TOKEN'] ?? '') === $rules['app_token']
        && preg_match('/^[a-f0-9]{40}$/', $_SERVER['HTTP_X_BC'] ?? '') === 1
        && ctype_digit($time) && abs((int) $time - (int) (microtime(true) * 1000)) < 300_000
        && hash_equals(expectedSign($rules, $uri, $time, $_SERVER['HTTP_USER_ID'] ?? '0'), $_SERVER['HTTP_SIGN'] ?? '');

    // Every session token seen or handed out, so the workload can look for each
    // one in the app's logs (workload:run, "Session tokens or keys found in logs").
    if (preg_match('/^[a-f0-9]{40}$/', $_SERVER['HTTP_X_BC'] ?? '') === 1) {
        $redis->sAdd("fake-upstream:secrets:{$account}", $_SERVER['HTTP_X_BC']);
    }

    if (! $signed) {
        respond($redis, $account, 400, ['error' => ['code' => 401, 'message' => 'Please refresh the page']]);
    }

    // A guest session cookie, as OnlyFans sets one on its responses.
    if (! isset($_COOKIE['sess'])) {
        $sess = bin2hex(random_bytes(12));
        $redis->sAdd("fake-upstream:secrets:{$account}", $sess);
        header('Set-Cookie: sess='.$sess.'; Max-Age=864000; path=/; HttpOnly', false);
    }
} elseif (preg_match('#^/api/profiles/([A-Za-z0-9_.-]{1,64})$#', $path, $match)) {
    $username = $match[1];

    // Accounts are recognised by the hash of their bearer token; the token itself
    // is never stored here.
    $token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $account = $token === '' ? false : $redis->hGet('fake-upstream:tokens', hash('sha256', $token));

    if ($account === false) {
        respond($redis, null, 401, ['error' => 'Unauthenticated']);
    }
} else {
    respond($redis, null, 404, ['error' => 'Not Found']);
}

$scenario = json_decode((string) $redis->get("fake-upstream:scenario:{$account}"), true) ?: [];
$requestNumber = $redis->incr("fake-upstream:requests:{$account}");
$now = microtime(true);

if (($latency = (int) ($scenario['latency_ms'] ?? 0)) > 0) {
    usleep($latency * 1000);
}

if (($scenario['storm_until'] ?? 0) > $now) {
    respond($redis, $account, 429, ['error' => 'Too Many Requests']);
}

if (($limit = (int) ($scenario['per_second'] ?? 0)) > 0) {
    $window = "fake-upstream:window:{$account}:".(int) $now;
    $count = $redis->incr($window);
    $redis->expire($window, 5);

    if ($count > $limit) {
        respond($redis, $account, 429, ['error' => 'Too Many Requests']);
    }
}

if (($every = (int) ($scenario['error_every'] ?? 0)) > 0 && $requestNumber % $every === 0) {
    respond($redis, $account, 500, null);
}

$likes = (int) ($scenario['likes'] ?? 121000);
$revision = (int) ($scenario['revision'] ?? 11);
$profile = ['username' => $username, 'name' => ucfirst($username), 'postsCount' => 10];
$format = $scenario['format'] ?? 'new';

if ($format === 'mixed') {
    $format = $requestNumber % 2 === 0 ? 'new' : 'old';
}

respond($redis, $account, 200, match ($format) {
    'old' => [...$profile, 'likes' => $likes, 'revision' => $revision],
    'onlyfans' => ['id' => crc32($username), ...$profile, 'favoritedCount' => $likes],
    'fansapi' => ['data' => [...$profile, 'favoritedCount' => $likes], '_meta' => ['_cache' => ['is_cached' => false, 'cached_at' => gmdate('c')]]],
    default => ['profile' => [...$profile, 'likes' => $likes], 'revision' => $revision],
});
