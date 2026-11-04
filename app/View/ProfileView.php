<?php

namespace App\View;

use App\Models\Profile;
use App\Refresh\Outcome;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Presentation helpers for the profile pages: how an outcome looks, what a
 * profile's refresh state means in words. Reads only; never changes data.
 */
final class ProfileView
{
    /** Chip tone of a refresh outcome: good, warn (retried or harmless), bad, or neutral (none yet). */
    public static function tone(?string $outcome): string
    {
        if ($outcome === null) {
            return 'neutral';
        }

        $case = Outcome::tryFrom($outcome);

        return match (true) {
            $case === null => 'bad',
            $case->isSuccess() => 'good',
            $case->isRetryable(), $case === Outcome::Stale, $case === Outcome::CacheTooOld, $case === Outcome::InFlight => 'warn',
            default => 'bad',
        };
    }

    /** One line on what an outcome means, for tooltips and the legend. */
    public static function meaning(?string $outcome): string
    {
        return match ($outcome) {
            null => 'No upstream attempt recorded yet',
            'success' => 'Valid response with a newer revision: data written',
            'unchanged' => 'Valid response, same revision as stored: data confirmed',
            'stale_ignored' => 'An older revision arrived late: ignored, data kept',
            'cache_too_old' => 'Fans API answered from an old cache: asked again for live data',
            'cache_still_too_old' => 'Live data was asked for and the snapshot was still old: not stored, retried later',
            'signature_rejected' => 'OnlyFans refused the request signature: rules reloaded, new session, retried',
            'rules_unavailable' => 'No usable signing rules: nothing sent, retried later',
            'rate_limited' => 'HTTP 429: the account pauses, the refresh retries later',
            'server_error' => 'HTTP 5xx: retried with backoff, data kept',
            'timeout' => 'Timeout, 408 or 504: retried with backoff, data kept',
            'invalid_response' => 'The response failed validation: data kept',
            'not_found' => 'HTTP 404 or 410: the profile was not found upstream',
            'auth_failed' => 'HTTP 401 or 403: the session or API key was refused',
            'client_error' => 'Another HTTP 4xx: failed at once, data kept',
            'gave_up' => 'Retries ran out or the job kept failing',
            'in_flight' => 'Sent, no answer recorded: still running, or the worker died before recording it (counted as an attempt)',
            default => 'Unknown outcome',
        };
    }

    /** Outcomes grouped by tone, for the legend under the profile list. */
    public static function legend(): array
    {
        $groups = ['good' => [], 'warn' => [], 'bad' => []];

        foreach (Outcome::cases() as $case) {
            $groups[self::tone($case->value)][] = $case->value;
        }

        return $groups;
    }

    /**
     * Failing now: the most recent attempts failed and no success came after
     * them. The dashboard count (ProfileController::stats) uses the same rule.
     */
    public static function isFailing(Profile $profile): bool
    {
        return $profile->consecutive_failures > 0
            || ($profile->last_failure_at !== null
                && ($profile->last_success_at === null || $profile->last_failure_at->gt($profile->last_success_at)));
    }

    /** "5m ago", "in 23h", "just now". */
    public static function relative(CarbonInterface $at, int $parts = 1): string
    {
        if (abs($at->diffInSeconds(now())) < 5) {
            return 'just now';
        }

        $span = $at->diffForHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'short' => true, 'parts' => $parts]);

        return $at->isFuture() ? 'in '.$span : $span.' ago';
    }

    /**
     * Where the profile is in its refresh cycle, from the last attempt (which set
     * next_refresh_at) to the next refresh.
     *
     * @return array{state: string, percent: float, span: ?string, backoff: bool}
     */
    public static function cycle(Profile $profile): array
    {
        $from = $profile->last_attempt_at;
        $to = $profile->next_refresh_at;

        if ($profile->isRefreshPending()) {
            return ['state' => 'inflight', 'percent' => 100.0, 'span' => null, 'backoff' => false];
        }

        // Without a claim, a failing profile's next_refresh_at was set by its
        // final failure: a backoff, not the regular interval.
        $backoff = self::isFailing($profile);

        if ($to === null || ! $to->isFuture()) {
            return ['state' => 'due', 'percent' => 100.0, 'span' => null, 'backoff' => $backoff];
        }

        if ($from === null || ! $from->lt($to)) {
            return ['state' => 'scheduled', 'percent' => 0.0, 'span' => null, 'backoff' => $backoff];
        }

        $total = $from->diffInSeconds($to);
        $percent = max(0.0, min(100.0, $from->diffInSeconds(now()) / $total * 100));

        return ['state' => 'scheduled', 'percent' => round($percent, 1), 'span' => self::duration($total), 'backoff' => $backoff];
    }

    /** "24 h", "1.5 h", "48 min". */
    public static function duration(float $seconds): string
    {
        $minutes = (int) round($seconds / 60);

        return match (true) {
            $minutes < 90 => $minutes.' min',
            $minutes % 60 === 0 => intdiv($minutes, 60).' h',
            default => round($minutes / 60, 1).' h',
        };
    }

    /** The headline of the refresh health panel. @return array{tone: string, icon: string, title: string, text: string} */
    public static function verdict(Profile $profile): array
    {
        $failure = $profile->last_failure_reason ?? $profile->last_attempt_status;

        if ($profile->isRefreshPending()) {
            if (self::isFailing($profile) && Outcome::tryFrom((string) $profile->last_attempt_status)?->isRetryable()) {
                return [
                    'tone' => 'warn', 'icon' => 'refresh', 'title' => 'Retrying',
                    'text' => "The last attempt was {$profile->last_attempt_status} ({$failure}). The refresh keeps its claim and "
                        .'retries with backoff; the stored data is untouched.',
                ];
            }

            return [
                'tone' => 'accent', 'icon' => 'refresh', 'title' => 'Refresh in flight',
                'text' => 'Queued '.self::relative($profile->refresh_queued_at).'. A Horizon worker holds the claim until it finishes; this page updates on its own.',
            ];
        }

        if ($profile->last_attempt_at === null) {
            return [
                'tone' => 'neutral', 'icon' => 'info', 'title' => 'Never refreshed',
                'text' => 'No upstream attempt yet. The scheduler queues it within a minute.',
            ];
        }

        if (self::isFailing($profile)) {
            $count = $profile->consecutive_failures;
            $streak = $count > 1 ? "{$count} failed refreshes in a row. " : '';
            $data = $profile->last_success_at
                ? 'Stored data is from the last success, '.self::relative($profile->last_success_at).'.'
                : 'No valid response has been stored yet.';

            return [
                'tone' => 'bad', 'icon' => 'x-circle', 'title' => 'Failing',
                'text' => $streak.'Last failure '.self::relative($profile->last_failure_at ?? $profile->last_attempt_at).": {$failure}. {$data}",
            ];
        }

        $text = $profile->last_success_at
            ? 'Last refresh succeeded '.self::relative($profile->last_success_at).'.'
            : 'Last attempt '.self::relative($profile->last_attempt_at).'.';
        $text .= match ($profile->last_attempt_status) {
            'stale_ignored' => ' The latest answer carried an older revision and was ignored; the stored data was kept.',
            'unchanged' => ' The upstream confirmed the stored revision.',
            default => '',
        };
        $text .= $profile->next_refresh_at?->isFuture()
            ? ' Next refresh '.self::relative($profile->next_refresh_at).'.'
            : ' The next refresh is due now.';

        return ['tone' => 'good', 'icon' => 'check-circle', 'title' => 'Healthy', 'text' => $text];
    }

    /** Up to two letters for the avatar fallback. */
    public static function initials(Profile $profile): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', (string) ($profile->name ?: $profile->username), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];

        $letters = count($words) > 1 && preg_match('/^\p{L}/u', $words[1])
            ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1)
            : mb_substr($words[0], 0, 2);

        return mb_strtoupper($letters);
    }

    /** A stable colour per profile for its avatar and banner. */
    public static function hue(Profile $profile): int
    {
        return crc32($profile->username) % 360;
    }

    /** OnlyFans "about" is HTML: keep its line breaks, drop every tag. The caller escapes the result. */
    public static function aboutText(?string $about): string
    {
        $text = preg_replace(['~<br\s*/?>~i', '~</p>~i'], ["\n", "\n\n"], (string) $about);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]*\n[ \t]*(\n[ \t]*)+/", "\n\n", $text));
    }

    /** OnlyFans revisions are request times and Fans API ones snapshot times, in ms; the workload uses small integers. */
    public static function revisionTime(?int $revision): ?Carbon
    {
        return $revision !== null && $revision > 1_000_000_000_000 ? Carbon::createFromTimestampMs($revision, 'UTC') : null;
    }
}
