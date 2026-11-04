<?php

namespace App\Upstream\OnlyFans;

/**
 * The `sign` header of an OnlyFans api2 request, as the web app computes it:
 *
 *   sha1 = SHA-1 hex of "static_param\ntime\npath?query\nuser_id"
 *   checksum = sum of the ASCII codes of sha1 at checksum_indexes + checksum_constant
 *   sign = "prefix:sha1:hex(|checksum|):suffix"
 *
 * The path is the request path as sent (with its query string); `time` is the
 * same Unix-milliseconds value sent in the `time` header; user_id is "0" for a
 * guest session.
 */
class RequestSigner
{
    public function sign(SigningRules $rules, string $pathWithQuery, int $timeMs, string $userId): string
    {
        $sha1 = sha1(implode("\n", [$rules->staticParam, (string) $timeMs, $pathWithQuery, $userId]));

        $checksum = $rules->checksumConstant;

        foreach ($rules->checksumIndexes as $index) {
            $checksum += ord($sha1[$index]);
        }

        return $rules->prefix.':'.$sha1.':'.dechex(abs($checksum)).':'.$rules->suffix;
    }
}
