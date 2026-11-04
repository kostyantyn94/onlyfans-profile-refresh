<?php

namespace App\Upstream;

use App\Models\Account;
use App\Upstream\OnlyFans\SigningRulesUnavailable;
use Closure;
use Illuminate\Http\Client\ConnectionException;

/** One upstream request for a profile. Never throws for an HTTP status. */
interface ProfileClient
{
    /**
     * @param  bool  $fresh  ask for live data instead of a cached copy, where the upstream caches
     * @param  ?Closure  $beforeSend  called right before the request goes out, once everything
     *                                that could fail without sending (rules, session) has succeeded
     *
     * @throws ConnectionException on connect/read timeouts and network errors
     * @throws SigningRulesUnavailable when a signed request cannot be built (nothing was sent)
     */
    public function fetchProfile(Account $account, string $username, bool $fresh = false, ?Closure $beforeSend = null): UpstreamResponse;
}
