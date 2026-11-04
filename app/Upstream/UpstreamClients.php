<?php

namespace App\Upstream;

use App\Models\Account;
use App\Upstream\OnlyFans\OnlyFansClient;

/** The client an account's driver speaks. */
class UpstreamClients
{
    public function for(Account $account): ProfileClient
    {
        return match ($account->driver) {
            Account::DRIVER_FANSAPI => app(FansApiClient::class),
            default => app(OnlyFansClient::class),
        };
    }
}
