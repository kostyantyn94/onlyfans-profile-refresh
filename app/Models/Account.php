<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One upstream identity (an OnlyFans session, or a Fans API key). Upstream rate
 * limits apply per account, so cooldowns and concurrency limits are keyed by it.
 *
 * driver "onlyfans": signed requests to base_url/api2/v2 (OnlyFansClient). A
 * null api_key means a guest session; for a logged-in session it holds JSON
 * {"user_id", "cookie", "x_bc", "user_agent"} copied from that browser session.
 * driver "fansapi": base_url/api/profiles with api_key as the bearer key.
 */
class Account extends Model
{
    public const DRIVER_ONLYFANS = 'onlyfans';

    public const DRIVER_FANSAPI = 'fansapi';

    protected $fillable = ['slug', 'name', 'driver', 'base_url', 'api_key', 'max_concurrency'];

    protected $attributes = ['driver' => self::DRIVER_ONLYFANS];

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

    /** The Fans API key to send upstream; null in the database means "use FANSAPI_KEY". */
    public function credential(): ?string
    {
        return $this->api_key ?? config('services.fansapi.key');
    }

    /**
     * A logged-in OnlyFans session for this account, or null for a guest one.
     *
     * @return array{user_id: string, cookie: string, x_bc: string, user_agent?: string}|null
     */
    public function onlyFansCredential(): ?array
    {
        if ($this->driver !== self::DRIVER_ONLYFANS || $this->api_key === null) {
            return null;
        }

        $credential = json_decode($this->api_key, true);

        return is_array($credential) && isset($credential['user_id'], $credential['cookie'], $credential['x_bc']) ? $credential : null;
    }

    public function concurrencyLimit(): int
    {
        return max(1, $this->max_concurrency ?? (int) config('refresh.account_concurrency'));
    }
}
