<?php

namespace App\Services;

use App\Models\IntelligenceAnomaly;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Persists intelligence anomalies in Postgres and uses Redis as a short-lived
 * list cache (invalidated when sync changes rows or on acknowledge).
 */
class IntelligenceAnomalyService
{
    private const LIST_CACHE_TTL = 90;

    private const LIST_CACHE_PREFIX = 'intelligence:anomalies:list:v2:';

    /**
     * @return list<array<string, mixed>>
     */
    public function listForTenant(string $tenantId): array
    {
        if (! Schema::hasTable('intelligence_anomalies')) {
            return [];
        }

        $cacheKey = self::LIST_CACHE_PREFIX.$tenantId;

        $dirty = $this->syncBlockedFromAtlas($tenantId);
        $dirty = $this->ensureSyntheticBaseline($tenantId) || $dirty;

        if (! $dirty) {
            $cached = $this->redisGet($cacheKey);
            if ($cached !== null) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } else {
            $this->redisDel($cacheKey);
        }

        $rows = IntelligenceAnomaly::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('detected_at')
            ->limit(100)
            ->get();

        $data = $rows->map(fn (IntelligenceAnomaly $a) => $this->toApiShape($a))->values()->all();
        $this->redisSetex($cacheKey, self::LIST_CACHE_TTL, json_encode($data));

        return $data;
    }

    public function acknowledge(string $tenantId, string $userId, string $id): ?IntelligenceAnomaly
    {
        if (! Schema::hasTable('intelligence_anomalies')) {
            return null;
        }

        $anomaly = IntelligenceAnomaly::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $anomaly) {
            return null;
        }

        $anomaly->update([
            'resolved_at' => now(),
            'acknowledged_by' => $userId,
        ]);

        $this->redisDel(self::LIST_CACHE_PREFIX.$tenantId);

        return $anomaly->fresh();
    }

    private function syncBlockedFromAtlas(string $tenantId): bool
    {
        if (! Schema::hasTable('atlas_interactions')) {
            return false;
        }

        $dirty = false;

        $blocked = DB::table('atlas_interactions')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>', now()->subHours(48))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'user_input', 'execution_result', 'created_at']);

        foreach ($blocked as $row) {
            $exec = json_decode($row->execution_result ?? '{}', true) ?: [];
            if (($exec['status'] ?? '') !== 'blocked') {
                continue;
            }

            $fingerprint = 'atlas_blocked:'.$row->id;

            $model = IntelligenceAnomaly::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'fingerprint' => $fingerprint,
                ],
                [
                    'severity' => 'high',
                    'title' => 'Atlas request blocked by policy',
                    'description' => Str::limit((string) $row->user_input, 500),
                    'detected_at' => $row->created_at,
                    'source' => 'atlas_blocked',
                    'source_ref' => (string) $row->id,
                    'metadata' => [
                        'interaction_id' => (string) $row->id,
                    ],
                ]
            );

            if ($model->wasRecentlyCreated) {
                $dirty = true;
            }
        }

        return $dirty;
    }

    private function ensureSyntheticBaseline(string $tenantId): bool
    {
        $hasBlocked = IntelligenceAnomaly::query()
            ->where('tenant_id', $tenantId)
            ->where('source', 'atlas_blocked')
            ->whereNull('resolved_at')
            ->exists();

        if ($hasBlocked) {
            $deleted = (int) IntelligenceAnomaly::query()
                ->where('tenant_id', $tenantId)
                ->where('fingerprint', 'synthetic:baseline')
                ->delete();

            return $deleted > 0;
        }

        $model = IntelligenceAnomaly::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'fingerprint' => 'synthetic:baseline',
            ],
            [
                'severity' => 'low',
                'title' => 'No policy blocks detected',
                'description' => 'Baseline health signal. It disappears automatically when blocked Atlas interactions are recorded.',
                'detected_at' => now()->subHour(),
                'source' => 'synthetic',
                'source_ref' => null,
                'metadata' => [],
            ]
        );

        return $model->wasRecentlyCreated;
    }

    /**
     * @return array<string, mixed>
     */
    private function toApiShape(IntelligenceAnomaly $a): array
    {
        return [
            'id' => $a->id,
            'severity' => $a->severity,
            'title' => $a->title,
            'description' => $a->description,
            'detected_at' => $a->detected_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'resolved' => $a->isResolved(),
            'source' => $a->source,
        ];
    }

    private function redisGet(string $key): ?string
    {
        try {
            $v = Redis::get($key);

            return is_string($v) ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function redisSetex(string $key, int $ttl, string $value): void
    {
        try {
            Redis::setex($key, $ttl, $value);
        } catch (\Throwable) {
            // Redis optional — DB remains source of truth after cache miss.
        }
    }

    private function redisDel(string $key): void
    {
        try {
            Redis::del($key);
        } catch (\Throwable) {
        }
    }
}
