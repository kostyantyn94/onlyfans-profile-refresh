<?php

namespace App\Refresh;

use App\Models\Profile;
use App\Upstream\ProfileSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every write a refresh makes to a profile. Data columns change only through
 * apply(), and only for a strictly newer revision; failures touch bookkeeping
 * columns only.
 */
class ProfileStore
{
    public function __construct(private RefreshPolicy $policy) {}

    public function apply(Profile $profile, ProfileSnapshot $snapshot, string $refreshId, CarbonImmutable $now): Outcome
    {
        return DB::transaction(function () use ($profile, $snapshot, $refreshId, $now) {
            // One conditional UPDATE: of two racing responses, only a newer
            // revision can win, whatever order the workers commit in.
            $applied = Profile::query()
                ->whereKey($profile->id)
                ->where(fn ($query) => $query->whereNull('revision')->orWhere('revision', '<', $snapshot->revision))
                ->update([
                    ...$snapshot->attributes,
                    'likes_count' => $snapshot->likes,
                    'revision' => $snapshot->revision,
                    'raw' => json_encode($snapshot->raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ...$this->successColumns(Outcome::Applied, $snapshot->likes, $now),
                ]);

            if ($applied === 1) {
                $outcome = Outcome::Applied;
            } elseif ((int) Profile::query()->whereKey($profile->id)->value('revision') === $snapshot->revision) {
                // The version we already hold: data confirmed, nothing to write.
                $outcome = Outcome::Unchanged;
                Profile::query()->whereKey($profile->id)->update($this->successColumns($outcome, $snapshot->likes, $now));
            } else {
                // An older version arrived after a newer one was accepted. Push
                // the next refresh out a little (never earlier than planned), or
                // an upstream stuck on an old revision would be asked every minute.
                $outcome = Outcome::Stale;
                $retry = $now->addMinutes((int) config('refresh.failed_retry_after_minutes'))->toDateTimeString();
                Profile::query()->whereKey($profile->id)->update([
                    'last_attempt_at' => $now,
                    'last_attempt_status' => $outcome->value,
                    'next_refresh_at' => DB::raw("GREATEST(COALESCE(next_refresh_at, '{$retry}'), '{$retry}')"),
                    'updated_at' => $now,
                ]);
            }

            $this->releaseClaim($profile->id, $refreshId);

            return $outcome;
        });
    }

    /**
     * A failed attempt. The last valid data and last_success_at stay as they are.
     * A final failure also gives the claim back and schedules a later retry.
     */
    public function recordFailure(Profile $profile, Outcome $outcome, string $reason, string $refreshId, bool $final, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($profile, $outcome, $reason, $refreshId, $final, $now) {
            Profile::query()->whereKey($profile->id)->update([
                'last_attempt_at' => $now,
                'last_attempt_status' => $outcome->value,
                'last_failure_at' => $now,
                'last_failure_reason' => Str::limit($reason, 480),
                'consecutive_failures' => DB::raw('consecutive_failures + 1'),
                'updated_at' => $now,
            ]);

            if ($final) {
                $failures = (int) Profile::query()->whereKey($profile->id)->value('consecutive_failures');

                Profile::query()
                    ->whereKey($profile->id)
                    ->where('pending_refresh_id', $refreshId)
                    ->update([
                        'refresh_queued_at' => null,
                        'pending_refresh_id' => null,
                        'next_refresh_at' => $now->addMinutes($this->failureBackoffMinutes($failures)),
                    ]);
            }
        });
    }

    /** Only the refresh that holds the claim may clear it (a replayed old job may not). */
    public function releaseClaim(int $profileId, string $refreshId): void
    {
        Profile::query()
            ->whereKey($profileId)
            ->where('pending_refresh_id', $refreshId)
            ->update(['refresh_queued_at' => null, 'pending_refresh_id' => null]);
    }

    /** 60 min after the first failed refresh, doubling per consecutive failure, at most 72 h. */
    private function failureBackoffMinutes(int $consecutiveFailures): int
    {
        $base = (int) config('refresh.failed_retry_after_minutes');

        return (int) min($base * 2 ** max(0, $consecutiveFailures - 1), (int) config('refresh.interval_hours.default') * 60);
    }

    private function successColumns(Outcome $outcome, int $likes, CarbonImmutable $now): array
    {
        return [
            'last_attempt_at' => $now,
            'last_attempt_status' => $outcome->value,
            'last_success_at' => $now,
            'consecutive_failures' => 0,
            'next_refresh_at' => $this->policy->nextRefreshAt($likes, $now),
            'updated_at' => $now,
        ];
    }
}
