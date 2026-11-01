<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The real Fans API account. api_key stays null: the key is read from
        // FANSAPI_KEY at request time and never stored in the database.
        $account = Account::query()->updateOrCreate(
            ['slug' => 'fansapi'],
            ['name' => 'Fans API', 'base_url' => config('services.fansapi.base_url'), 'api_key' => null],
        );

        Profile::query()->createOrFirst(['account_id' => $account->id, 'username' => 'madison420ivy']);
    }
}
