<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Direct, signed requests to onlyfans.com with a guest session: no
        // credential needed for a public profile.
        $onlyFans = Account::query()->updateOrCreate(
            ['slug' => 'onlyfans'],
            ['name' => 'OnlyFans (guest session)', 'driver' => Account::DRIVER_ONLYFANS, 'base_url' => config('services.onlyfans.base_url'), 'api_key' => null],
        );

        Profile::query()->createOrFirst(['account_id' => $onlyFans->id, 'username' => 'madison420ivy']);

        // The managed Fans API endpoint, for comparison (profiles:fetch --account=fansapi).
        // api_key stays null: the key is read from FANSAPI_KEY at request time.
        Account::query()->updateOrCreate(
            ['slug' => 'fansapi'],
            ['name' => 'Fans API', 'driver' => Account::DRIVER_FANSAPI, 'base_url' => config('services.fansapi.base_url'), 'api_key' => null],
        );
    }
}
