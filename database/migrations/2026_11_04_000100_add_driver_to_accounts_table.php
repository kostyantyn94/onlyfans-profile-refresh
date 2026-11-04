<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which client an account speaks: "onlyfans" (signed requests straight to
     * onlyfans.com, the default) or "fansapi" (the managed Fans API endpoint).
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('driver', 16)->default('onlyfans')->after('name');
        });

        // Every account created before this column existed used the Fans API protocol.
        DB::table('accounts')->update(['driver' => 'fansapi']);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('driver');
        });
    }
};
