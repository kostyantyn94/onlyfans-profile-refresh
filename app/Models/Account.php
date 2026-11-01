<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One upstream identity (API key / team). Upstream rate limits apply per
 * account, so cooldowns and concurrency limits are keyed by it.
 */
class Account extends Model
{
    protected $fillable = ['slug', 'name', 'base_url', 'api_key', 'max_concurrency'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'max_concurrency' => 'integer',
        ];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    /** The key to send upstream; null in the database means "use FANSAPI_KEY". */
    public function credential(): ?string
    {
        return $this->api_key ?? config('services.fansapi.key');
    }

    public function concurrencyLimit(): int
    {
        return max(1, $this->max_concurrency ?? (int) config('refresh.account_concurrency'));
    }
}
