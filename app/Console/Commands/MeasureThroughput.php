<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Refresh\RefreshScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * A rough local throughput and memory check: N due profiles on one account
 * that always answers, queued by the real scheduler path and processed by the
 * running Horizon workers. Gives a per-worker rate to reason about capacity;
 * it is not a load test of production.
 */
class MeasureThroughput extends Command
{
    protected $signature = 'workload:throughput
        {--profiles=5000 : Profiles to create and refresh}
        {--latency=50 : Milliseconds the fake upstream adds per request}
        {--concurrency=8 : Concurrency limit of the benchmark account}
        {--timeout=600 : Stop waiting after this many seconds}';

    protected $description = 'Measure scheduler memory and Horizon throughput on a single healthy account';

    public function handle(RefreshScheduler $scheduler, MasterSupervisorRepository $masters): int
    {
        if ($masters->all() === []) {
            $this->error('Horizon is not running. Start it: docker compose up -d horizon');

            return self::FAILURE;
        }

        $count = (int) $this->option('profiles');
        $fake = Redis::connection('fake_upstream');
        config(['refresh.handler' => 'v2']);

        Account::query()->where('slug', 'bench')->delete();
        $fake->del('fake-upstream:status:bench', 'fake-upstream:requests:bench');
        $fake->sadd('fake-upstream:accounts', 'bench');
        $fake->set('fake-upstream:scenario:bench', json_encode(['format' => 'new', 'latency_ms' => (int) $this->option('latency')]));

        $account = Account::query()->create([
            'slug' => 'bench',
            'name' => 'Throughput benchmark',
            'driver' => Account::DRIVER_ONLYFANS,
            'base_url' => rtrim(config('refresh.fake_upstream_url'), '/').'/bench',
            'max_concurrency' => (int) $this->option('concurrency'),
        ]);

        $now = now();
        foreach (array_chunk(range(1, $count), 1000) as $chunk) {
            DB::table('profiles')->insert(array_map(fn ($i) => [
                'account_id' => $account->id,
                'username' => sprintf('bench-%06d', $i),
                'next_refresh_at' => $now->copy()->subMinute(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        $logOffset = is_file(storage_path('logs/refresh.log')) ? filesize(storage_path('logs/refresh.log')) : 0;
        $memoryBefore = memory_get_usage(true);
        $started = microtime(true);
        $queued = $scheduler->enqueueDue(max: $count);
        $enqueueSeconds = microtime(true) - $started;
        $enqueuePeakMb = (memory_get_peak_usage(true) - $memoryBefore) / 1048576;

        $this->info(sprintf('Queued %d refreshes in %.2f s (scheduler memory grew by %.1f MB). Processing...', $queued, $enqueueSeconds, $enqueuePeakMb));

        $deadline = $started + (int) $this->option('timeout');
        do {
            usleep(500_000);
            $waiting = DB::table('profiles')->where('account_id', $account->id)->whereNotNull('refresh_queued_at')->count();
        } while ($waiting > 0 && microtime(true) < $deadline);

        $total = microtime(true) - $started;
        $durations = DB::table('refresh_attempts')->where('account_id', $account->id)->orderBy('duration_ms')->pluck('duration_ms')->all();
        $workers = (int) config('horizon.environments.local.refresh.maxProcesses');

        $log = (string) file_get_contents(storage_path('logs/refresh.log'), offset: $logOffset);
        preg_match_all('/"account":"bench".*?"memory_peak_mb":([\d.]+)/', $log, $memory);

        $this->table(['metric', 'value'], [
            ['profiles refreshed', $count - $waiting],
            ['unfinished at the end', $waiting],
            ['Horizon refresh workers', $workers],
            ['upstream latency added', $this->option('latency').' ms'],
            ['scheduler: claim + dispatch', sprintf('%.2f s for %d (%.0f/s), memory +%.1f MB', $enqueueSeconds, $queued, $queued / max($enqueueSeconds, 0.001), $enqueuePeakMb)],
            ['wall time until all refreshed', sprintf('%.1f s', $total)],
            ['throughput', sprintf('%.0f refreshes/s (%.1f per worker)', ($count - $waiting) / $total, ($count - $waiting) / $total / max($workers, 1))],
            ['upstream call p50 / p95', $this->percentile($durations, 50).' / '.$this->percentile($durations, 95).' ms'],
            ['worker peak memory (max seen)', ($memory[1] ? max(array_map('floatval', $memory[1])) : '?').' MB'],
            ['upstream requests', array_sum(array_map('intval', $fake->hgetall('fake-upstream:status:bench') ?: []))],
        ]);

        return self::SUCCESS;
    }

    private function percentile(array $sorted, int $p): int
    {
        return $sorted === [] ? 0 : (int) $sorted[(int) floor((count($sorted) - 1) * $p / 100)];
    }
}
