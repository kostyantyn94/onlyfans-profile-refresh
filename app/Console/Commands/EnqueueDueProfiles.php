<?php

namespace App\Console\Commands;

use App\Refresh\RefreshScheduler;
use Illuminate\Console\Command;

class EnqueueDueProfiles extends Command
{
    protected $signature = 'profiles:enqueue-due {--max=10000 : Most profiles to queue in one run}';

    protected $description = 'Queue a refresh for every profile that is due and not already pending';

    public function handle(RefreshScheduler $scheduler): int
    {
        $queued = $scheduler->enqueueDue(max: (int) $this->option('max'));

        $this->info("Queued {$queued} refresh(es).");

        return self::SUCCESS;
    }
}
