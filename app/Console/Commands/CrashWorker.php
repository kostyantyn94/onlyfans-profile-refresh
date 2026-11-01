<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\RefreshScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Kills a real Horizon worker (SIGKILL) in the middle of a refresh, before the
 * queue is told the job finished, then waits for Redis to hand the same job
 * out again after retry_after and checks what the second delivery did.
 */
class CrashWorker extends Command
{
    protected $signature = 'workload:crash
        {--at=after-write : after-write (DB committed, not acknowledged) or before-write (fetched, nothing written)}
        {--wait=150 : Seconds to wait for the redelivery}';

    protected $description = 'Kill a worker mid-refresh and check the redelivered job';

    public function handle(RefreshScheduler $scheduler, MasterSupervisorRepository $masters): int
    {
        $point = (string) $this->option('at');

        if (! in_array($point, ['after-write', 'before-write'], true) || ! app()->isLocal()) {
            $this->error('--at must be after-write or before-write, and APP_ENV must be local.');

            return self::FAILURE;
        }

        if ($masters->all() === []) {
            $this->error('Horizon is not running. Start it: docker compose up -d horizon');

            return self::FAILURE;
        }

        config(['refresh.handler' => 'v2']);
        $fake = Redis::connection('fake_upstream');

        Account::query()->where('slug', 'crash')->delete();
        $fake->del('fake-upstream:status:crash');
        $token = 'fake-'.Str::random(32);
        $fake->hset('fake-upstream:tokens', hash('sha256', $token), 'crash');
        $fake->set('fake-upstream:scenario:crash', json_encode(['format' => 'new', 'likes' => 121_000, 'revision' => 11]));

        $account = Account::query()->create(['slug' => 'crash', 'name' => 'Crash test', 'base_url' => config('refresh.fake_upstream_url'), 'api_key' => $token]);
        $profile = Profile::query()->create(['account_id' => $account->id, 'username' => 'crash-001']);
        $profile->forceFill(['likes_count' => 120_000, 'revision' => 10, 'last_success_at' => now()->subDay()])->save();

        Redis::set('refresh:crash:'.$point.':'.$profile->id, '1', 'EX', 300);
        $refreshId = $scheduler->enqueue($profile);
        $queuedAt = microtime(true);
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->info("Refresh {$refreshId} queued; the worker that picks it up will be killed {$point}.");

        $killed = $this->waitFor(fn () => Redis::exists('refresh:crash:'.$point.':'.$profile->id) === 0, 30);
        if (! $killed) {
            $this->error('No worker picked the job up within 30 s.');

            return self::FAILURE;
        }

        usleep(500_000);
        $afterCrash = $profile->fresh();
        $this->line(sprintf('Worker killed after %.1f s. Profile now: likes=%s revision=%s, claim %s, attempts recorded: %d, jobs reserved in Redis: %d.',
            microtime(true) - $queuedAt, $afterCrash->likes_count, $afterCrash->revision,
            $afterCrash->pending_refresh_id ? 'still held' : 'released',
            RefreshAttempt::query()->where('refresh_id', $refreshId)->count(),
            Redis::zcard('queues:refresh:reserved')));

        $this->line("Waiting for Redis to hand the job out again (retry_after = {$retryAfter} s)...");
        // After the write, the redelivered job finds its refresh finished and skips
        // (a log line, no upstream call); before the write, it does the work.
        $log = storage_path('logs/refresh.log');
        $offset = is_file($log) ? filesize($log) : 0;
        $redelivered = $this->waitFor(fn () => $point === 'after-write'
            ? str_contains((string) file_get_contents($log, offset: $offset), '"refresh.skipped_not_pending","context":{"refresh_id":"'.$refreshId)
            : RefreshAttempt::query()->where('refresh_id', $refreshId)->count() >= 1, (int) $this->option('wait'));

        $final = $profile->fresh();
        $attempts = RefreshAttempt::query()->where('refresh_id', $refreshId)->orderBy('id')->get();

        $this->table(['check', 'result'], [
            ['redelivered', $redelivered ? sprintf('yes, %.0f s after queueing', microtime(true) - $queuedAt) : 'NO (timed out)'],
            ['profile rows for crash-001', Profile::query()->where('account_id', $account->id)->where('username', 'crash-001')->count()],
            ['final likes / revision', "{$final->likes_count} / {$final->revision}"],
            ['attempts recorded (outcome, job attempt)', $attempts->map(fn ($a) => "{$a->outcome} (#{$a->job_attempt})")->implode(', ') ?: 'none'],
            ['second delivery', $point === 'after-write' ? 'skipped: refresh already finished, no upstream call' : 'did the refresh'],
            ['upstream requests', array_sum(array_map('intval', $fake->hgetall('fake-upstream:status:crash') ?: []))],
            ['claim', $final->pending_refresh_id ? 'still held' : 'released'],
            ['last_attempt_status', $final->last_attempt_status],
            ['failed jobs', DB::table('failed_jobs')->where('payload', 'like', '%'.$refreshId.'%')->count()],
        ]);

        return $redelivered ? self::SUCCESS : self::FAILURE;
    }

    private function waitFor(callable $condition, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }

            usleep(250_000);
        }

        return false;
    }
}
