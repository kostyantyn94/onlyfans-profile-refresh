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
    // OnlyFans refused the request signature: the rules are reloaded and the
    // session renewed before the next try.
    case SignatureRejected = 'signature_rejected';
    // No source had signing rules OnlyFans was not rejecting: nothing was sent.
    case RulesUnavailable = 'rules_unavailable';

    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case Timeout = 'timeout';
    case InvalidResponse = 'invalid_response';
    case NotFound = 'not_found';
    case AuthFailed = 'auth_failed';
    case ClientError = 'client_error';
    // The job kept throwing or crashing its worker: the refresh is abandoned.
    case GaveUp = 'gave_up';

    // Written just before the request is sent and replaced by its result. One left
    // behind is a request whose worker died before recording the answer: it still
    // counts towards the refresh's attempts.
    case InFlight = 'in_flight';

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
        return in_array($this, [
            self::RateLimited, self::ServerError, self::Timeout,
            self::SignatureRejected, self::RulesUnavailable,
        ], true);
    }
}
