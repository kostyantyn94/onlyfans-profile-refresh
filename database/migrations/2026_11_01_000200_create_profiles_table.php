<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('username');

            // Last accepted upstream data. Null until the first valid response.
            $table->unsignedBigInteger('upstream_id')->nullable();
            $table->string('name')->nullable();
            $table->text('about')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('header_url', 2048)->nullable();
            $table->boolean('is_verified')->nullable();
            $table->decimal('subscribe_price', 10, 2)->nullable();
            $table->unsignedBigInteger('likes_count')->nullable();
            $table->unsignedInteger('posts_count')->nullable();
            $table->unsignedInteger('photos_count')->nullable();
            $table->unsignedInteger('videos_count')->nullable();
            $table->unsignedInteger('audios_count')->nullable();
            $table->unsignedInteger('medias_count')->nullable();
            $table->unsignedBigInteger('subscribers_count')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // The whole upstream profile object, as received.
            $table->jsonb('raw')->nullable();
            // Strictly increasing upstream version; older responses never overwrite newer ones.
            $table->unsignedBigInteger('revision')->nullable();

            // Refresh bookkeeping, kept apart from the data above.
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_attempt_status', 32)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_reason', 500)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('next_refresh_at')->nullable();

            // Claim: set while a refresh is queued or running, so it is not queued twice.
            $table->timestamp('refresh_queued_at')->nullable();
            $table->uuid('pending_refresh_id')->nullable();

            $table->timestamps();

            // One row per profile and account, however many jobs race to create it.
            $table->unique(['account_id', 'username']);
        });

        // The scheduler only looks at unclaimed profiles; keep that scan cheap.
        DB::statement('CREATE INDEX profiles_due_idx ON profiles (next_refresh_at) WHERE refresh_queued_at IS NULL');
        DB::statement('CREATE INDEX profiles_claimed_idx ON profiles (refresh_queued_at) WHERE refresh_queued_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
