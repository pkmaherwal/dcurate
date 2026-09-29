<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches public API responses per campaign; any change to published items bumps the version.
 */
class CampaignFeedCache
{
    /**
     * @template TValue
     *
     * @param  array<string, mixed>  $parameters
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public static function remember(int $campaignId, string $variant, array $parameters, Closure $callback): mixed
    {
        ksort($parameters);

        $key = sprintf('api:campaign:%d:v%d:%s:%s', $campaignId, static::version($campaignId), $variant, md5((string) json_encode($parameters)));

        return Cache::remember($key, (int) config('curation.api.cache_seconds', 300), $callback);
    }

    public static function bump(int $campaignId): void
    {
        Cache::forever(static::versionKey($campaignId), static::version($campaignId) + 1);
    }

    protected static function version(int $campaignId): int
    {
        return (int) Cache::get(static::versionKey($campaignId), 1);
    }

    protected static function versionKey(int $campaignId): string
    {
        return "api:campaign:{$campaignId}:version";
    }
}
