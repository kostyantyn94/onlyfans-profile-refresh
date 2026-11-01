<?php

/**
 * Local stand-in for the Fans API profile endpoint, GET /api/profiles/{username},
 * served by `php -S` in its own container (compose service "fake-upstream").
 * Used by `php artisan workload:run`; the tests use Http::fake instead.
 *
 * Each account behaves as described by a JSON scenario in Redis, written by
 * the workload command (key fake-upstream:scenario:{account}):
 *
 *   format       "old" (top-level likes), "new" (profile.likes), "mixed"
 *                (alternates old/new, like a partial upstream rollout) or "fansapi"
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

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/health') {
    echo 'ok';
    exit;
}

if (! preg_match('#^/api/profiles/([A-Za-z0-9_.-]{1,64})$#', $path, $match)) {
    respond($redis, null, 404, ['error' => 'Not Found']);
}

$username = $match[1];

// Accounts are recognised by the hash of their bearer token; the token itself
// is never stored here.
$token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$account = $token === '' ? false : $redis->hGet('fake-upstream:tokens', hash('sha256', $token));

if ($account === false) {
    respond($redis, null, 401, ['error' => 'Unauthenticated']);
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
    'fansapi' => ['data' => [...$profile, 'favoritedCount' => $likes], '_meta' => ['_cache' => ['is_cached' => false, 'cached_at' => gmdate('c')]]],
    default => ['profile' => [...$profile, 'likes' => $likes], 'revision' => $revision],
});
