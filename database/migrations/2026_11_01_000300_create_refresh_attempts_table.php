<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per upstream request. A completed queue job is not proof of a
     * refresh; this table (and profiles.last_success_at) is.
     */
    public function up(): void
    {
        Schema::create('refresh_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->uuid('refresh_id')->index();
            $table->string('job_id')->nullable();
            $table->unsignedSmallInteger('job_attempt')->nullable();
            $table->string('handler', 16);
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('revision')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedInteger('retry_in_seconds')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['profile_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_attempts');
    }
};
