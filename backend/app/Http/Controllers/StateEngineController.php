<?php

namespace App\Http\Controllers;

use App\Services\FeatureFlag;
use App\Services\StateTransitionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * StateEngineController
 *
 * Read-first API for the State Transition Engine (STE).
 * All routes are guarded by role:super_admin + can.do:ste.view.
 * Simulation additionally requires can.do:ste.simulate + platform.ste_simulate flag.
 */
class StateEngineController extends Controller
{
    public function __construct(
        private readonly StateTransitionEngine $engine,
    ) {}

    public function matrix(Request $request): JsonResponse
    {
        $this->assertReadEnabled();

        $chain = $request->query('chain', 'session_lifecycle');
        $tenantId = $request->query('tenant_id');

        return response()->json([
            'chain' => $chain,
            'tenant_id' => $tenantId,
            'matrix' => $this->engine->matrix($chain, $tenantId),
            'damping' => StateTransitionEngine::DAMPING,
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    public function conditionalMatrix(Request $request): JsonResponse
    {
        $this->assertReadEnabled();

        $chain = $request->query('chain', 'session_lifecycle');
        $tenantId = $request->query('tenant_id');

        // Accept tag filters as ?tag.surface=voice&tag.tool_name=search
        $tagFilter = [];
        foreach ($request->query() as $k => $v) {
            if (str_starts_with($k, 'tag.')) {
                $tagFilter[substr($k, 4)] = $v;
            }
        }

        return response()->json([
            'chain' => $chain,
            'tenant_id' => $tenantId,
            'tag_filter' => $tagFilter,
            'matrix' => $this->engine->conditionalMatrix($chain, $tagFilter, $tenantId),
            'damping' => StateTransitionEngine::DAMPING,
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    public function dropoffs(Request $request): JsonResponse
    {
        $this->assertReadEnabled();

        $chain = $request->query('chain', 'session_lifecycle');
        $tenantId = $request->query('tenant_id');

        return response()->json([
            'chain' => $chain,
            'tenant_id' => $tenantId,
            'dropoffs' => $this->engine->dropoffs($chain, $tenantId),
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    public function winningTags(Request $request): JsonResponse
    {
        $this->assertReadEnabled();

        $chain = $request->query('chain', 'session_lifecycle');
        $metric = $request->query('metric', 'activation');
        $tenantId = $request->query('tenant_id');
        $limit = min(100, max(1, (int) $request->query('limit', 20)));

        return response()->json([
            'chain' => $chain,
            'metric' => $metric,
            'tenant_id' => $tenantId,
            'winning_tags' => $this->engine->winningTags($chain, $metric, $tenantId, $limit),
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/ste/simulate — proxies to the inference plane's Monte Carlo
     * simulator. Budget: ≤ 300 ms for runs=1000, steps=10.
     */
    public function simulate(Request $request): JsonResponse
    {
        $this->assertReadEnabled();

        if (! FeatureFlag::on('platform.ste_simulate')) {
            return response()->json([
                'error' => 'platform.ste_simulate is disabled',
            ], 503);
        }

        $payload = $request->validate([
            'chain' => 'required|string|in:session_lifecycle,tenant_lifecycle',
            'start_state' => 'required|string|max:64',
            'steps' => 'sometimes|integer|min:1|max:50',
            'runs' => 'sometimes|integer|min:10|max:5000',
            'tenant_id' => 'sometimes|uuid',
            'seed' => 'sometimes|integer',
        ]);

        // Provide the matrix directly to avoid a second DB round-trip inside inference
        $matrix = isset($payload['tenant_id'])
            ? $this->engine->matrix($payload['chain'], $payload['tenant_id'])
            : $this->engine->matrix($payload['chain']);

        $terminal = $payload['chain'] === 'session_lifecycle'
            ? StateTransitionEngine::TERMINAL_STATES_SESSION
            : StateTransitionEngine::TERMINAL_STATES_TENANT;

        $body = array_merge($payload, [
            'matrix' => $matrix,
            'damping' => StateTransitionEngine::DAMPING,
            'terminal_states' => $terminal,
        ]);

        try {
            $resp = Http::timeout(2)
                ->acceptJson()
                ->post(rtrim(config('services.inference.url'), '/').'/ste/simulate', $body);

            if (! $resp->successful()) {
                return response()->json([
                    'error' => 'inference_plane_error',
                    'upstream_code' => $resp->status(),
                    'upstream_body' => $resp->json() ?? $resp->body(),
                ], 502);
            }

            return response()->json($resp->json());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'inference_plane_unreachable',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * GET /api/ste/unmapped — shows event types without a mapping row yet.
     */
    public function unmapped(): JsonResponse
    {
        $this->assertReadEnabled();

        $rows = DB::table('ste_unmapped_events')
            ->orderByDesc('count')
            ->limit(200)
            ->get();

        return response()->json([
            'unmapped_events' => $rows,
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/ste/lag — observability helper for the atlas.ste.lag alert.
     */
    public function lag(): JsonResponse
    {
        $this->assertReadEnabled();

        return response()->json([
            'lag_seconds' => $this->engine->lagSeconds(),
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private function assertReadEnabled(): void
    {
        if (! FeatureFlag::on('platform.ste_read')) {
            abort(503, 'platform.ste_read is disabled');
        }
    }
}
