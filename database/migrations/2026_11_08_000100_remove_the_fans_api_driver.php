<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The "fansapi" driver (the managed Fans API endpoint) is gone: requests go
     * to onlyfans.com only. Its accounts are removed with their profiles and
     * attempts (foreign keys cascade).
     */
    public function up(): void
    {
        DB::table('accounts')->where('driver', 'fansapi')->delete();
    }

    public function down(): void
    {
        // The removed accounts are not recreated.
    }
};
