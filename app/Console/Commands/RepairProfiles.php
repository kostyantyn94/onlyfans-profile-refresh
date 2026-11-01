<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Profile;
use App\Refresh\RefreshScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Repairs profiles written by the broken handler during the incident.
 *
 * Re-fetching alone is not enough: the old handler stored likes 0 together
 * with the upstream's real revision (11), so a fresh response at revision 11
 * would count as "unchanged" and the zero would stay. Every profile marked
 * successful since the incident started is suspect, so its revision is
 * cleared and a refresh is queued; the fixed handler then writes whatever the
 * upstream returns, after validation.
 */
class RepairProfiles extends Command
{
    protected $signature = 'profiles:repair
        {--since= : When the incident started (e.g. "2026-11-01 18:00"), UTC}
        {--account= : Only this account slug}
        {--dry-run : Count the profiles, change nothing}';

    protected $description = 'Re-fetch profiles written during the incident, ignoring their stored revision';

    public function handle(RefreshScheduler $scheduler): int
    {
        if (! $this->option('since')) {
            $this->error('--since is required.');

            return self::FAILURE;
        }

        $since = CarbonImmutable::parse($this->option('since'), 'UTC');
        $query = Profile::query()->where('last_success_at', '>=', $since);

        if ($slug = $this->option('account')) {
            $query->whereIn('account_id', Account::query()->where('slug', $slug)->select('id'));
        }

        $count = (clone $query)->count();
        $this->info("{$count} profile(s) marked successful since {$since->toDateTimeString()} UTC.");

        if ($this->option('dry-run') || $count === 0) {
            return self::SUCCESS;
        }

        $queued = 0;

        $query->select(['id', 'account_id'])->chunkById(500, function ($profiles) use ($scheduler, &$queued) {
            Profile::query()->whereIn('id', $profiles->pluck('id'))->update(['revision' => null]);

            foreach ($profiles as $profile) {
                $queued += $scheduler->enqueue($profile) === null ? 0 : 1;
            }
        });

        $this->info("Revision cleared and refresh queued for {$queued} profile(s); ".($count - $queued).' already had a refresh pending.');

        return self::SUCCESS;
    }
}
