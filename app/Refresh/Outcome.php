<?php

namespace App\Refresh;

/** What one upstream attempt achieved. Stored in refresh_attempts.outcome. */
enum Outcome: string
{
    // Valid response, newer revision: data written.
    case Applied = 'success';
    // Valid response, same revision as stored: data confirmed, nothing to write.
    case Unchanged = 'unchanged';
    // Valid response, older revision than stored (arrived late): ignored.
    case Stale = 'stale_ignored';

    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case Timeout = 'timeout';
    case InvalidResponse = 'invalid_response';
    case NotFound = 'not_found';
    case AuthFailed = 'auth_failed';
    case ClientError = 'client_error';
    // Retry window over, or the job kept throwing: the refresh is abandoned.
    case GaveUp = 'gave_up';

    public static function fromHttpStatus(int $status): self
    {
        return match (true) {
            $status === 429 => self::RateLimited,
            $status === 408, $status === 504 => self::Timeout,
            $status >= 500 => self::ServerError,
            $status === 404, $status === 410 => self::NotFound,
            $status === 401, $status === 403 => self::AuthFailed,
            default => self::ClientError,
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Applied || $this === self::Unchanged;
    }

    /** Worth another try for the same refresh; everything else fails it at once. */
    public function isRetryable(): bool
    {
        return in_array($this, [self::RateLimited, self::ServerError, self::Timeout], true);
    }
}
