<?php

namespace App\Refresh;

use App\Jobs\LegacyRefreshProfile;
use App\Jobs\RefreshProfile;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Queues refreshes. A profile is claimed (refresh_queued_at + pending_refresh_id)
 * with a conditional UPDATE before its job is dispatched, so the scheduler, the
 * UI and the CLI can never queue the same profile twice.
 */
class RefreshScheduler
{
    /** Queue one profile now. Returns the refresh id, or null if one is already pending. */
    public function enqueue(Profile $profile, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        $refreshId = (string) Str::uuid();

        $claimed = Profile::query()
            ->whereKey($profile->id)
            ->where(fn ($query) => $query
                ->whereNull('refresh_queued_at')
                ->orWhere('refresh_queued_at', '<', $this->claimExpiry($now)))
            ->update(['refresh_queued_at' => $now, 'pending_refresh_id' => $refreshId]);

        if ($claimed === 0) {
            Log::channel('refresh')->info('refresh.already_pending', [
                'profile_id' => $profile->id,
                'account_id' => $profile->account_id,
            ]);

            return null;
        }

        $this->dispatch($profile->id, $profile->account_id, $refreshId, 'manual');

        return $refreshId;
    }

    /**
     * Claim and queue every due profile, in batches so memory stays flat however
     * many profiles exist. FOR UPDATE SKIP LOCKED lets two schedulers run at
     * once without claiming the same rows. Lost claims are taken over first, in
     * a query of their own, so each query can walk its own partial index.
     */
    public function enqueueDue(?CarbonImmutable $now = null, int $batch = 500, int $max = 50_000): int
    {
        $now ??= CarbonImmutable::now();
        $queued = 0;

        $lostClaims = <<<'SQL'
            SELECT id FROM profiles
            WHERE refresh_queued_at < ?
            ORDER BY refresh_queued_at
            LIMIT ? FOR UPDATE SKIP LOCKED
            SQL;

        $due = <<<'SQL'
            SELECT id FROM profiles
            WHERE refresh_queued_at IS NULL AND (next_refresh_at IS NULL OR next_refresh_at <= ?)
            ORDER BY next_refresh_at NULLS FIRST, id
            LIMIT ? FOR UPDATE SKIP LOCKED
            SQL;

        foreach ([[$lostClaims, $this->claimExpiry($now)], [$due, $now]] as [$select, $before]) {
            while ($queued < $max) {
                $limit = min($batch, $max - $queued);

                $rows = DB::select(
                    "UPDATE profiles SET refresh_queued_at = ?, pending_refresh_id = gen_random_uuid()
                     WHERE id IN ({$select})
                     RETURNING id, account_id, pending_refresh_id",
                    [$now, $before, $limit],
                );

                foreach ($rows as $row) {
                    $this->dispatch($row->id, $row->account_id, $row->pending_refresh_id, 'schedule');
                }

                $queued += count($rows);

                if (count($rows) < $limit) {
                    break;
                }
            }
        }

        return $queued;
    }

    public function makeJob(int $profileId, int $accountId, string $refreshId): RefreshProfile|LegacyRefreshProfile
    {
        return config('refresh.handler') === 'legacy'
            ? new LegacyRefreshProfile($profileId, $accountId, $refreshId)
            : new RefreshProfile($profileId, $accountId, $refreshId);
    }

    private function dispatch(int $profileId, int $accountId, string $refreshId, string $source): void
    {
        Log::channel('refresh')->info('refresh.queued', [
            'refresh_id' => $refreshId,
            'profile_id' => $profileId,
            'account_id' => $accountId,
            'source' => $source,
            'handler' => config('refresh.handler'),
        ]);

        dispatch($this->makeJob($profileId, $accountId, $refreshId));
    }

    private function claimExpiry(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMinutes((int) config('refresh.claim_expires_after_minutes'));
    }
}
