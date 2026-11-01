<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Waits for the workload accounts to finish, then checks their stored data. */
class CheckWorkload extends Command
{
    protected $signature = 'workload:check {--wait=180 : Seconds to wait for pending refreshes}';

    protected $description = 'Check the stored data of the workload accounts (busy, calm)';

    public function handle(): int
    {
        $accounts = Account::query()->whereIn('slug', ['busy', 'calm'])->pluck('id', 'slug');
        $deadline = microtime(true) + (int) $this->option('wait');

        while (DB::table('profiles')->whereIn('account_id', $accounts)->whereNotNull('refresh_queued_at')->exists() && microtime(true) < $deadline) {
            usleep(500_000);
        }

        $rows = [];
        foreach ($accounts as $slug => $id) {
            $stats = DB::selectOne(
                'SELECT count(*) AS total,
                        count(*) FILTER (WHERE likes_count = 121000 AND revision = 11) AS correct,
                        count(*) FILTER (WHERE likes_count = 120000 AND revision = 10) AS kept,
                        count(*) FILTER (WHERE refresh_queued_at IS NOT NULL) AS pending
                 FROM profiles WHERE account_id = ?', [$id]);

            $rows[] = [$slug, $stats->total, $stats->correct, $stats->kept, $stats->total - $stats->correct - $stats->kept, $stats->pending];
        }

        $this->table(['account', 'profiles', 'correct (121,000 / rev 11)', 'kept last valid (120,000 / rev 10)', 'wrong', 'still pending'], $rows);

        return self::SUCCESS;
    }
}
