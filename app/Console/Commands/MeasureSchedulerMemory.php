<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;

/**
 * Shows that the scheduler's memory does not grow with the number of due
 * profiles: runs `profiles:enqueue-due` as its own process for a small and a
 * large batch and compares their peak memory. The jobs go to a queue no worker
 * listens on, which is deleted afterwards.
 */
class MeasureSchedulerMemory extends Command
{
    protected $signature = 'workload:scheduler-memory {--profiles=30000 : Due profiles for the large run}';

    protected $description = 'Compare scheduler peak memory for a small and a large number of due profiles';

    private const QUEUE = 'scheduler-memory-test';

    public function handle(): int
    {
        $large = (int) $this->option('profiles');
        $account = $this->prepare($large);
        $rows = [];

        foreach ([intdiv($large, 10), $large] as $count) {
            DB::table('profiles')->where('account_id', $account->id)->update(['refresh_queued_at' => null, 'pending_refresh_id' => null]);
            Redis::del('queues:'.self::QUEUE);

            $process = new Process(['php', 'artisan', 'profiles:enqueue-due', '--max='.$count, '--no-ansi'], base_path(), ['REFRESH_QUEUE' => self::QUEUE]);
            $process->setTimeout(600)->mustRun();

            preg_match('/Queued (\d+) refresh\(es\) in ([\d.]+) s, peak memory ([\d.]+) MB/', $process->getOutput(), $m);
            $rows[] = [number_format((int) $m[1]), $m[2].' s', round((int) $m[1] / max((float) $m[2], 0.001)).'/s', $m[3].' MB', Redis::llen('queues:'.self::QUEUE)];
        }

        $account->delete();
        Redis::del('queues:'.self::QUEUE);

        $this->table(['profiles queued', 'time', 'rate', 'peak memory (process)', 'jobs in Redis'], $rows);

        return self::SUCCESS;
    }

    private function prepare(int $count): Account
    {
        Account::query()->where('slug', 'memtest')->delete();
        $account = Account::query()->create(['slug' => 'memtest', 'name' => 'Scheduler memory test', 'base_url' => 'http://127.0.0.1:9', 'api_key' => 'unused']);

        $now = now();
        foreach (array_chunk(range(1, $count), 2000) as $chunk) {
            DB::table('profiles')->insert(array_map(fn ($i) => [
                'account_id' => $account->id,
                'username' => sprintf('mem-%06d', $i),
                'next_refresh_at' => $now->copy()->subMinute(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return $account;
    }
}
