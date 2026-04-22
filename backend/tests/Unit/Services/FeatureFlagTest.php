<?php

namespace Tests\Unit\Services;

use App\Services\FeatureFlag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

/**
 * FeatureFlagTest
 *
 * Verifies resolution order, env override, Redis override,
 * per-tenant override, and cache-busting.
 */
class FeatureFlagTest extends TestCase
{
    public function test_returns_config_default_when_no_overrides(): void
    {
        Redis::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('remember')->andReturnUsing(fn($k, $t, $fn) => $fn());

        $value = FeatureFlag::value('atlas.usage_aggregates_v2');
        $this->assertSame('off', $value);
    }

    public function test_redis_global_overrides_config(): void
    {
        Redis::shouldReceive('get')
            ->with('feature:atlas.usage_aggregates_v2')
            ->andReturn('on');
        Cache::shouldReceive('remember')->andReturnUsing(fn($k, $t, $fn) => $fn());

        $this->assertTrue(FeatureFlag::on('atlas.usage_aggregates_v2'));
    }

    public function test_redis_tenant_override_wins_over_global(): void
    {
        $tenantId = 'abc-123';

        Redis::shouldReceive('get')
            ->with("feature:atlas.usage_aggregates_v2:tenant:{$tenantId}")
            ->andReturn('off');

        Redis::shouldReceive('get')
            ->with('feature:atlas.usage_aggregates_v2')
            ->andReturn('on');   // global is on, tenant is off

        Cache::shouldReceive('remember')->andReturnUsing(fn($k, $t, $fn) => $fn());

        $this->assertFalse(FeatureFlag::on('atlas.usage_aggregates_v2', $tenantId));
        $this->assertTrue(FeatureFlag::on('atlas.usage_aggregates_v2')); // global still on
    }

    public function test_fallback_returns_true_for_fallback_value(): void
    {
        Redis::shouldReceive('get')->andReturn('fallback');
        Cache::shouldReceive('remember')->andReturnUsing(fn($k, $t, $fn) => $fn());

        $this->assertTrue(FeatureFlag::fallback('atlas.copy.empty_state'));
        $this->assertFalse(FeatureFlag::on('atlas.copy.empty_state'));
    }

    public function test_set_writes_to_redis_and_busts_cache(): void
    {
        Redis::shouldReceive('set')
            ->with('feature:atlas.usage_aggregates_v2.shadow', 'on')
            ->once();
        Cache::shouldReceive('forget')
            ->with('featureflag:atlas.usage_aggregates_v2.shadow')
            ->once();

        FeatureFlag::set('atlas.usage_aggregates_v2.shadow', 'on');
    }

    public function test_scalar_temperature_flag_returns_float(): void
    {
        Redis::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('remember')->andReturnUsing(fn($k, $t, $fn) => $fn());

        $temp = FeatureFlag::value('atlas.bandit.temperature');
        $this->assertIsFloat($temp);
        $this->assertSame(1.0, $temp);
    }

    public function test_redis_unavailable_falls_back_to_config(): void
    {
        Redis::shouldReceive('get')->andThrow(new \Exception('Connection refused'));
        Cache::shouldReceive('remember')->andReturnUsing(fn($k, $t, $fn) => $fn());

        // Should not throw; falls back to config default
        $value = FeatureFlag::value('atlas.usage_aggregates_v2');
        $this->assertSame('off', $value);
    }
}
