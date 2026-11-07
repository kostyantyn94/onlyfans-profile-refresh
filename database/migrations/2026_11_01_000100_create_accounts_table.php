<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An account is one upstream identity (API key / team). Rate limits apply
     * per account, so isolation and cooldowns are keyed by it.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('base_url');
            // Encrypted at rest. A logged-in OnlyFans session (JSON); null = a guest session.
            $table->text('api_key')->nullable();
            $table->unsignedSmallInteger('max_concurrency')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
