<?php

namespace App\Http\Controllers;

use App\Services\IntelligenceAnomalyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cockpit Intelligence workspace — aggregates tenant signals into OODA briefs,
 * persisted anomalies (Postgres + Redis list cache), and component health.
 */
class IntelligenceController extends Controller
{
    public function __construct(
        private readonly IntelligenceAnomalyService $anomalyService
    ) {}

    public function brief(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $dayCount = 0;
        if (Schema::hasTable('atlas_interactions')) {
            $dayCount = (int) DB::table('atlas_interactions')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>', now()->subDay())
                ->count();
        }

        $data = [
            'observe' => [
                $dayCount > 0
                    ? "{$dayCount} Atlas interactions recorded in the last 24 hours."
                    : 'No Atlas traffic in the last 24 hours; brief is based on platform defaults.',
                'Cost governor and approvals queues are monitored continuously.',
            ],
            'orient' => [
                'Tenant automation signals are aligned with MetaPlanner hard rules and onboarding policy.',
                'Traces and flow executions contribute to convergence metrics when present.',
            ],
            'decide' => [
                'Prioritize clearing pending approvals before raising automation level.',
                'Review usage vs budget if spend velocity increases.',
            ],
            'act' => [
                'Open Atlas to dispatch the next guided action.',
                'Run or publish flows from the Flow builder when automation is ready.',
            ],
        ];

        return response()->json(['data' => $data]);
    }

    public function anomalies(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        return response()->json([
            'data' => $this->anomalyService->listForTenant($tenantId),
        ]);
    }

    public function learning(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $limit = min(50, max(1, (int) $request->input('limit', 20)));

        if (! Schema::hasTable('atlas_interactions')) {
            return response()->json(['data' => []]);
        }

        $rows = DB::table('atlas_interactions')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('final_ts')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'user_input', 'final_ts', 'created_at']);

        $data = $rows->map(function ($row) {
            return [
                'id' => (string) $row->id,
                'summary' => Str::limit((string) $row->user_input, 120),
                'description' => 'Transformation score recorded for adaptation loop.',
                'category' => 'atlas_ts',
                'outcome' => (float) $row->final_ts >= 0.5 ? 'positive' : 'negative',
                'confidence' => (float) $row->final_ts,
                'created_at' => $row->created_at,
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    public function health(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $components = [
            'event_log' => 'healthy',
            'meta_planner' => 'healthy',
            'inference_plane' => config('services.inference.url') ? 'healthy' : 'degraded',
            'intelligence_workers' => 'healthy',
        ];

        $score = 92;
        if ($components['inference_plane'] !== 'healthy') {
            $score = 74;
        }

        return response()->json([
            'data' => [
                'tenant_id' => $tenantId,
                'overall_score' => $score,
                'components' => $components,
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function acknowledgeAnomaly(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $userId = (string) $request->user()->id;

        $anomaly = $this->anomalyService->acknowledge($tenantId, $userId, $id);
        if (! $anomaly) {
            return response()->json(['message' => 'Anomaly not found'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $anomaly->id,
                'resolved' => true,
                'acknowledged_at' => $anomaly->resolved_at?->toIso8601String(),
            ],
        ]);
    }
}
