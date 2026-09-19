<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * FeatureFlag
 *
 * Resolves feature flags at runtime using the following priority chain:
 *
 *   1. Redis per-tenant key   feature:<name>:tenant:<tenantId>
 *   2. Redis global key       feature:<name>
 *   3. Environment variable   FEATURE_<UPPER_SNAKE>
 *   4. config/features.php    default
 *
 * Results are cached in-process for 5 seconds to avoid hot-path Redis RTT.
 *
 * Usage:
 *   FeatureFlag::on('atlas.usage_aggregates_v2')
 *   FeatureFlag::on('atlas.usage_aggregates_v2', $tenantId)
 *   FeatureFlag::value('atlas.bandit.temperature')   // returns scalar default
 */
class FeatureFlag
{
    private const TTL = 5;   // in-process cache TTL in seconds

    /**
     * Returns true when the flag resolves to "on".
     * For copy-surface flags also returns true when value is "on"
     * (fallback surface-flag returns false so copy falls back to template).
     */
    public static function on(string $name, ?string $tenantId = null): bool
    {
        $val = static::value($name, $tenantId);
        if (is_bool($val)) {
            return $val;
        }

        return strtolower((string) $val) === 'on';
    }

    /**
     * Returns true when the flag resolves to "fallback" (copy surfaces only).
     */
    public static function fallback(string $name): bool
    {
        $val = static::value($name);

        return strtolower((string) $val) === 'fallback';
    }

    /**
     * Returns the raw resolved value for scalar flags
     * (e.g. atlas.bandit.temperature → 1.0).
     */
    public static function value(string $name, ?string $tenantId = null): mixed
    {
        $cacheKey = 'featureflag:'.$name.($tenantId ? ':t:'.$tenantId : '');

        return Cache::remember($cacheKey, static::TTL, function () use ($name, $tenantId) {
            return static::resolve($name, $tenantId);
        });
    }

    /**
     * Force-set a flag in Redis (used by the artisan command and tests).
     * Also busts the local cache entry.
     */
    public static function set(string $name, string $value, ?string $tenantId = null): void
    {
        $redisKey = $tenantId
            ? 'feature:'.$name.':tenant:'.$tenantId
            : 'feature:'.$name;

        Redis::set($redisKey, $value);

        // Bust local cache
        $cacheKey = 'featureflag:'.$name.($tenantId ? ':t:'.$tenantId : '');
        Cache::forget($cacheKey);
    }

    /**
     * Delete an override from Redis, falling back to config/env.
     */
    public static function forget(string $name, ?string $tenantId = null): void
    {
        $redisKey = $tenantId
            ? 'feature:'.$name.':tenant:'.$tenantId
            : 'feature:'.$name;

        Redis::del($redisKey);

        $cacheKey = 'featureflag:'.$name.($tenantId ? ':t:'.$tenantId : '');
        Cache::forget($cacheKey);
    }

    /**
     * Return all registered flags and their current resolved values.
     */
    public static function all(): array
    {
        $flags = array_keys((array) config('features', []));

        return collect($flags)->mapWithKeys(function (string $flag) {
            return [$flag => static::value($flag)];
        })->all();
    }

    // -----------------------------------------------------------------------
    // Private resolution logic
    // -----------------------------------------------------------------------

    private static function resolve(string $name, ?string $tenantId): mixed
    {
        // 1. Per-tenant Redis override
        if ($tenantId) {
            $tenantVal = static::tryRedis('feature:'.$name.':tenant:'.$tenantId);
            if ($tenantVal !== null) {
                return $tenantVal;
            }
        }

        // 2. Global Redis override
        $globalVal = static::tryRedis('feature:'.$name);
        if ($globalVal !== null) {
            return $globalVal;
        }

        // 3. Config default (flat key lookup — flag names contain dots)
        $allFlags = config('features') ?? [];
        if (array_key_exists($name, $allFlags)) {
            return $allFlags[$name];
        }

        // 4. Hard-coded safe default
        return 'off';
    }

    private static function tryRedis(string $key): mixed
    {
        try {
            $val = Redis::get($key);

            return $val !== null ? $val : null;
        } catch (\Throwable) {
            // Redis unavailable — fall through to config
            return null;
        }
    }
}
