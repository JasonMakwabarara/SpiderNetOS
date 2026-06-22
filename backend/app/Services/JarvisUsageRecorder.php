<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Records background Atlas inference spend (OpenJarvis bridge) for billing surfaces.
 */
class JarvisUsageRecorder
{
    public const RESOURCE_TYPE = 'atlas_inference';

    public function record(string $tenantId, float $costUsd, ?string $userId = null, array $metadata = []): void
    {
        if ($costUsd <= 0) {
            return;
        }

        if (!Schema::hasTable('usage_records')) {
            return;
        }

        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'agent_id' => null,
            'resource_type' => self::RESOURCE_TYPE,
            'model' => $metadata['model'] ?? 'atlas-inference',
            'tokens_input' => 0,
            'tokens_output' => 0,
            'cost_usd' => round($costUsd, 6),
            'duration_ms' => $metadata['duration_ms'] ?? null,
            'status' => 'success',
            'recorded_at' => now(),
            'metadata' => json_encode(array_merge(['surface' => 'background'], $metadata)),
        ]);

        if (!Schema::hasTable('usage_daily_aggregates')) {
            return;
        }

        $date = now()->toDateString();
        $existing = DB::table('usage_daily_aggregates')
            ->where('tenant_id', $tenantId)
            ->where('date', $date)
            ->where('resource_type', self::RESOURCE_TYPE)
            ->first();

        DB::table('usage_daily_aggregates')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'date' => $date,
                'resource_type' => self::RESOURCE_TYPE,
            ],
            [
                'total_calls' => ($existing->total_calls ?? 0) + 1,
                'total_tokens' => $existing->total_tokens ?? 0,
                'total_cost' => round((float) ($existing->total_cost ?? 0) + $costUsd, 6),
                'cost_ceiling' => $existing->cost_ceiling ?? 0,
                'calculated_at' => now(),
            ],
        );
    }
}
