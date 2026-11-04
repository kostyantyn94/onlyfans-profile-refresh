<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\RefreshScheduler;
use App\Upstream\OnlyFans\SessionStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * A repeatable workload against the local fake upstream, processed by the
 * running Horizon workers. Two accounts: "busy" queues a burst first and runs
 * into rate limits and empty 500s; "calm" queues right after it and always gets
 * valid answers. Every profile starts at the task's last valid data (likes
 * 120,000, revision 10); the upstream now reports likes 121,000 at revision 11.
 *
 * Run the same workload with --handler=legacy and --handler=v2 to compare.
 */
class RunWorkload extends Command
{
    protected $signature = 'workload:run
        {--handler=v2 : v2 (the fix) or legacy (the incident handler)}
        {--busy=40 : Profiles on the busy account, queued first as one burst}
        {--calm=10 : Profiles on the calm account, queued right after}
        {--storm=6 : Seconds during which every busy request gets 429 (no Retry-After)}
        {--busy-per-second=5 : Busy account limit after the storm}
        {--error-every=10 : Every Nth busy request gets HTTP 500 with an empty body (0 = never)}
        {--format=mixed : Upstream format: old, new, mixed (alternating) or fansapi}
        {--latency=50 : Milliseconds added to every upstream response}
        {--rotate-rules=0 : Seconds after the start when the fake upstream switches to new signing rules (0 = never)}
        {--timeout=180 : Stop waiting after this many seconds}';

    protected $description = 'Run the two-account workload through Horizon and report what happened';

    private const EXPECTED = ['likes' => 121_000, 'revision' => 11];

    private const BASELINE = ['likes' => 120_000, 'revision' => 10];

    public function handle(RefreshScheduler $scheduler, MasterSupervisorRepository $masters): int
    {
        $handler = (string) $this->option('handler');

        if (! in_array($handler, ['v2', 'legacy'], true)) {
            $this->error('--handler must be v2 or legacy');

            return self::FAILURE;
        }

        if ($masters->all() === []) {
            $this->error('Horizon is not running. Start it: docker compose up -d horizon');

            return self::FAILURE;
        }

        $fake = Redis::connection('fake_upstream');
        $logOffset = is_file(storage_path('logs/refresh.log')) ? filesize(storage_path('logs/refresh.log')) : 0;

        $this->reset($fake);
        config(['refresh.handler' => $handler]);

        $start = microtime(true);
        $startedAt = CarbonImmutable::now();
        $accounts = [
            'busy' => $this->makeAccount('busy', $fake, [
                'latency_ms' => (int) $this->option('latency'),
                'format' => $this->option('format'),
                'storm_until' => $start + (float) $this->option('storm'),
                'per_second' => (int) $this->option('busy-per-second'),
                'error_every' => (int) $this->option('error-every'),
            ]),
            'calm' => $this->makeAccount('calm', $fake, [
                'latency_ms' => (int) $this->option('latency'),
                'format' => $this->option('format'),
            ]),
        ];

        if (($rotate = (float) $this->option('rotate-rules')) > 0) {
            $fake->set('fake-upstream:rotate_rules_at', (string) ($start + $rotate));
        }

        $profiles = [
            'busy' => $this->makeProfiles($accounts['busy'], (int) $this->option('busy')),
            'calm' => $this->makeProfiles($accounts['calm'], (int) $this->option('calm')),
        ];

        // The busy burst goes in first, so the calm account's jobs sit behind it.
        foreach (['busy', 'calm'] as $slug) {
            foreach ($profiles[$slug] as $profile) {
                $scheduler->enqueue($profile, $startedAt);
            }
        }

        $this->info(sprintf('Queued %d + %d refreshes with handler=%s in %.2f s. Waiting for Horizon...',
            count($profiles['busy']), count($profiles['calm']), $handler, microtime(true) - $start));

        [$timeline, $maxWait, $drainedAt] = $this->watch($accounts, $start, (int) $this->option('timeout'));

        $report = [
            'handler' => $handler,
            'started_at' => $startedAt->toIso8601String(),
            'parameters' => [
                'busy_profiles' => (int) $this->option('busy'),
                'calm_profiles' => (int) $this->option('calm'),
                'storm_seconds' => (float) $this->option('storm'),
                'busy_per_second_after_storm' => (int) $this->option('busy-per-second'),
                'busy_empty_500_every' => (int) $this->option('error-every'),
                'format' => $this->option('format'),
                'latency_ms' => (int) $this->option('latency'),
                'client' => 'onlyfans: signed api2 requests, guest session',
                'signing_rules_rotated_after_s' => (float) $this->option('rotate-rules') ?: null,
                'horizon_refresh_workers' => (int) config('horizon.environments.local.refresh.maxProcesses'),
                'account_concurrency' => (int) config('refresh.account_concurrency'),
                'legacy_retry' => $handler === 'legacy' ? config('refresh.legacy') : null,
            ],
            'accounts' => [],
            'timeline' => $timeline,
        ];

        foreach ($accounts as $slug => $account) {
            $report['accounts'][$slug] = $this->summarize($account, $fake, $startedAt, $maxWait[$slug], $drainedAt[$slug] ?? null);
        }

        $report['secrets_in_logs'] = $this->secretsInLogs($accounts, $logOffset);
        $report['failed_jobs'] = DB::table('failed_jobs')->where('failed_at', '>=', $startedAt)->count();

        $this->printReport($report);

        $path = storage_path('app/workload/'.$handler.'-'.$startedAt->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->line('Report: '.Str::after($path, base_path().'/'));

        return self::SUCCESS;
    }

    private function reset($fake): void
    {
        Account::query()->whereIn('slug', ['busy', 'calm'])->delete();

        // Leftovers of an earlier run: queued jobs, cooldowns, concurrency slots.
        $prefix = (string) config('database.redis.options.prefix');
        $redis = Redis::connection();
        $redis->del('queues:refresh', 'queues:refresh:delayed', 'queues:refresh:reserved', 'queues:refresh:notify');

        foreach (['refresh:cooldown:*', 'refresh:funnel:*'] as $pattern) {
            foreach ($redis->keys($pattern) as $key) {
                $redis->del(Str::after($key, $prefix));
            }
        }

        foreach ($fake->keys('fake-upstream:*') as $key) {
            $fake->del($key);
        }

        // Signing rules (and rejection counts) left from an earlier run, which
        // may have rotated the fake upstream's rules.
        $cache = Redis::connection('cache');
        foreach ($cache->keys('*onlyfans:rules*') as $key) {
            $cache->del(Str::after($key, $prefix));
        }
    }

    /** An OnlyFans account (guest session) on the fake upstream, which knows it by its path prefix. */
    private function makeAccount(string $slug, $fake, array $scenario): Account
    {
        $fake->sadd('fake-upstream:accounts', $slug);
        $fake->set('fake-upstream:scenario:'.$slug, json_encode($scenario));

        return Account::query()->create([
            'slug' => $slug,
            'name' => ucfirst($slug).' account (workload)',
            'driver' => Account::DRIVER_ONLYFANS,
            'base_url' => rtrim(config('refresh.fake_upstream_url'), '/').'/'.$slug,
        ]);
    }

    /** @return list<Profile> */
    private function makeProfiles(Account $account, int $count): array
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'account_id' => $account->id,
                'username' => sprintf('%s-%03d', $account->slug, $i),
                'likes_count' => self::BASELINE['likes'],
                'revision' => self::BASELINE['revision'],
                'last_attempt_at' => $now->copy()->subHours(25),
                'last_attempt_status' => 'success',
                'last_success_at' => $now->copy()->subHours(25),
                // Not due: the workload queues them itself, so the scheduler
                // container cannot pick some up first (with its own handler).
                'next_refresh_at' => $now->copy()->addDay(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Profile::query()->insert($rows);

        return Profile::query()->where('account_id', $account->id)->orderBy('id')->get()->all();
    }

    /**
     * Samples every 250 ms until both accounts have no unfinished refresh.
     * A refresh waits from the moment it is queued until it finishes (applied,
     * or failed for good); retries sitting in Redis's delayed set still wait.
     */
    private function watch(array $accounts, float $start, int $timeout): array
    {
        $timeline = [];
        $maxWait = ['busy' => 0.0, 'calm' => 0.0];
        $drainedAt = [];
        $lastSecond = -1;

        while (true) {
            $elapsed = microtime(true) - $start;
            $now = CarbonImmutable::now();
            $row = ['t' => (int) floor($elapsed)];

            foreach ($accounts as $slug => $account) {
                $stats = DB::selectOne(
                    'SELECT count(*) FILTER (WHERE refresh_queued_at IS NOT NULL) AS waiting,
                            min(refresh_queued_at) AS oldest,
                            count(*) FILTER (WHERE likes_count = ? AND revision = ?) AS refreshed
                     FROM profiles WHERE account_id = ?',
                    [self::EXPECTED['likes'], self::EXPECTED['revision'], $account->id],
                );

                $oldest = $stats->oldest ? max(0, $now->diffInSeconds(CarbonImmutable::parse($stats->oldest), absolute: true)) : 0;
                $maxWait[$slug] = max($maxWait[$slug], $oldest);

                if ((int) $stats->waiting === 0 && ! isset($drainedAt[$slug])) {
                    $drainedAt[$slug] = round($elapsed, 1);
                }

                $row[$slug] = ['waiting' => (int) $stats->waiting, 'oldest_wait_s' => round($oldest, 1), 'refreshed_ok' => (int) $stats->refreshed];
            }

            if ($row['t'] > $lastSecond) {
                $lastSecond = $row['t'];
                $timeline[] = $row;
                $this->line(sprintf('  t=%3ds  busy: %2d waiting, oldest %5.1fs, %2d ok  |  calm: %2d waiting, oldest %5.1fs, %2d ok',
                    $row['t'], $row['busy']['waiting'], $row['busy']['oldest_wait_s'], $row['busy']['refreshed_ok'],
                    $row['calm']['waiting'], $row['calm']['oldest_wait_s'], $row['calm']['refreshed_ok']));
            }

            if (count($drainedAt) === count($accounts)) {
                break;
            }

            if ($elapsed > $timeout) {
                $this->warn("Stopped waiting after {$timeout} s; some refreshes never finished.");
                break;
            }

            usleep(250_000);
        }

        return [$timeline, $maxWait, $drainedAt];
    }

    private function summarize(Account $account, $fake, CarbonImmutable $startedAt, float $maxWait, ?float $drainedAt): array
    {
        $profiles = Profile::query()->where('account_id', $account->id)->get();
        $statuses = array_map('intval', $fake->hgetall('fake-upstream:status:'.$account->slug) ?: []);
        ksort($statuses);
        $requests = array_sum($statuses);

        $verified = $profiles->filter(fn ($p) => $p->likes_count === self::EXPECTED['likes'] && $p->revision === self::EXPECTED['revision'])->count();
        $kept = $profiles->filter(fn ($p) => $p->likes_count === self::BASELINE['likes'] && $p->revision === self::BASELINE['revision'])->count();

        return [
            'refreshes_queued' => $profiles->count(),
            'refreshes_finished' => $profiles->whereNull('refresh_queued_at')->count(),
            // last_success_at has whole seconds: compare with the start's second,
            // or refreshes done within that first second are missed.
            'reported_successful' => $profiles->filter(fn ($p) => in_array($p->last_attempt_status, ['success', 'unchanged'], true)
                && $p->last_success_at?->gte($startedAt->startOfSecond()))->count(),
            'verified_successful' => $verified,
            'kept_last_valid_data' => $kept,
            'wrong_data' => $profiles->count() - $verified - $kept,
            'wrong_data_examples' => $profiles->reject(fn ($p) => in_array([$p->likes_count, $p->revision], [array_values(self::EXPECTED), array_values(self::BASELINE)], true))
                ->take(3)->map(fn ($p) => ['username' => $p->username, 'likes' => $p->likes_count, 'revision' => $p->revision])->values()->all(),
            'failed_for_good' => $profiles->whereNull('refresh_queued_at')
                ->filter(fn ($p) => ! in_array($p->last_attempt_status, ['success', 'unchanged'], true))->count(),
            'upstream_requests' => $requests,
            'upstream_statuses' => $statuses,
            'attempts_recorded_by_app' => RefreshAttempt::query()->where('account_id', $account->id)->count(),
            'attempts_per_verified_success' => $verified > 0 ? round($requests / $verified, 2) : null,
            'max_oldest_wait_s' => round($maxWait, 1),
            'all_finished_after_s' => $drainedAt,
        ];
    }

    /** Occurrences of the accounts' session tokens (x-bc, cookies) or keys in the logs. */
    private function secretsInLogs(array $accounts, int $offset): int
    {
        $path = storage_path('logs/refresh.log');
        $log = is_file($path) ? (string) file_get_contents($path, offset: $offset) : '';
        $log .= is_file(storage_path('logs/laravel.log')) ? (string) file_get_contents(storage_path('logs/laravel.log')) : '';

        $secrets = collect($accounts)->flatMap(function (Account $account) {
            $session = app(SessionStore::class)->for($account);

            return [$account->api_key, $session->xBc, ...array_values($session->cookies)];
        })->filter()->unique();

        return $secrets->sum(fn (string $secret) => substr_count($log, $secret));
    }

    private function printReport(array $report): void
    {
        $this->newLine();
        $this->info("Handler: {$report['handler']}");

        $rows = [];
        foreach (array_keys($report['accounts']['busy']) as $metric) {
            if (in_array($metric, ['wrong_data_examples'], true)) {
                continue;
            }

            $rows[] = [$metric, ...array_map(fn ($account) => is_array($account[$metric]) ? json_encode($account[$metric]) : ($account[$metric] ?? '-'), $report['accounts'])];
        }

        $this->table(['metric', 'busy', 'calm'], $rows);
        $this->line("Failed jobs in Horizon during the run: {$report['failed_jobs']}");
        $this->line("Session tokens or keys found in logs: {$report['secrets_in_logs']}");
    }
}
