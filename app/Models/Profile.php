<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Profile extends Model
{
    use Searchable;

    protected $fillable = ['account_id', 'username'];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'is_verified' => 'boolean',
            'subscribe_price' => 'decimal:2',
            'likes_count' => 'integer',
            'revision' => 'integer',
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'next_refresh_at' => 'datetime',
            'refresh_queued_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(RefreshAttempt::class);
    }

    /** Scout (database engine) searches these columns. */
    public function toSearchableArray(): array
    {
        return [
            'username' => $this->username,
            'name' => $this->name,
        ];
    }

    public function isRefreshPending(): bool
    {
        return $this->refresh_queued_at !== null;
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username, " \t\n\r\0\x0B@/"));
    }
}
