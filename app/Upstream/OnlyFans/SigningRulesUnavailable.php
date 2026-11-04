<?php

namespace App\Upstream\OnlyFans;

use RuntimeException;

/**
 * No source has signing rules that OnlyFans has not been rejecting: requests
 * would only collect more rejections, so none is sent.
 *
 * With $loading, there is no verdict yet: another worker is loading the rules
 * right now, and the caller should simply try again in a moment.
 */
class SigningRulesUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly bool $loading = false)
    {
        parent::__construct($message);
    }
}
