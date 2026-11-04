<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Profile;
use App\Refresh\RefreshScheduler;
use Illuminate\Console\Command;

class FetchProfile extends Command
{
    protected $signature = 'profiles:fetch
        {username : OnlyFans username, e.g. madison420ivy}
        {--account=onlyfans : Account slug: onlyfans (signed requests to onlyfans.com) or fansapi}
        {--queue : Hand the refresh to Horizon instead of running it in this process}';

    protected $description = 'Track a profile and refresh it from the upstream';

    public function handle(RefreshScheduler $scheduler): int
    {
        $account = Account::query()->where('slug', $this->option('account'))->first();

        if ($account === null) {
            $this->error("No account '{$this->option('account')}'. Run php artisan db:seed first.");

            return self::FAILURE;
        }

        // createOrFirst: a concurrent insert of the same username hits the unique
        // index and we read the existing row instead of creating a second one.
        $profile = Profile::query()->createOrFirst([
            'account_id' => $account->id,
            'username' => Profile::normalizeUsername($this->argument('username')),
        ]);

        $queueConnection = config('queue.default');

        if (! $this->option('queue')) {
            // Run the first attempt right here, through the same middleware.
            config(['queue.default' => 'sync']);
        }

        $refreshId = $scheduler->enqueue($profile);

        if ($refreshId === null) {
            $this->warn("A refresh for {$profile->username} is already pending (queued {$profile->fresh()->refresh_queued_at}).");

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            $this->info("Queued refresh {$refreshId} for {$profile->username}. Follow it: grep {$refreshId} storage/logs/refresh.log");

            return self::SUCCESS;
        }

        $profile->refresh();

        if ($profile->pending_refresh_id === $refreshId) {
            // Rate limited, failed temporarily or deferred by the account gate:
            // the sync driver cannot wait, so hand the retry to Horizon.
            $delay = $profile->attempts()->where('refresh_id', $refreshId)->latest('id')->value('retry_in_seconds') ?? 5;
            dispatch($scheduler->makeJob($profile->id, $profile->account_id, $refreshId)->onConnection($queueConnection)->delay($delay));
            $this->warn("Not finished yet; Horizon retries refresh {$refreshId} in {$delay} s.");
        }
        $attempt = $profile->attempts()->latest('id')->first();

        $this->table(['Field', 'Value'], [
            ['username', $profile->username],
            ['name', $profile->name],
            ['upstream id', $profile->upstream_id],
            ['likes', $profile->likes_count === null ? '-' : number_format($profile->likes_count)],
            ['posts / photos / videos', "{$profile->posts_count} / {$profile->photos_count} / {$profile->videos_count}"],
            ['verified', var_export($profile->is_verified, true)],
            ['subscribe price', $profile->subscribe_price],
            ['joined', $profile->joined_at?->toDateString()],
            ['revision', $profile->revision],
            ['fields stored (raw)', count($profile->raw ?? [])],
            ['last attempt', ($profile->last_attempt_status ?? '-').' at '.$profile->last_attempt_at],
            ['last success', $profile->last_success_at ?? '-'],
            ['last failure', $profile->last_failure_reason ?? '-'],
            ['next refresh', $profile->next_refresh_at ?? '-'],
            ['this attempt', $attempt ? "{$attempt->outcome}, HTTP {$attempt->http_status}, {$attempt->duration_ms} ms" : '-'],
            ['refresh id', $refreshId],
        ]);

        return $attempt?->outcome === 'success' || $attempt?->outcome === 'unchanged' ? self::SUCCESS : self::FAILURE;
    }
}
