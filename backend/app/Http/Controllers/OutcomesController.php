<?php

namespace App\Http\Controllers;

use App\Services\AtlasJarvisAugmentor;
use App\Services\EventStore;
use App\Services\IntelligenceGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * V2 cognitive outcome loop — weekly review surface (recommendations + autonomy).
 */
class OutcomesController extends Controller
{
    public function __construct(
        protected IntelligenceGateway $gateway,
        protected EventStore $eventStore,
        protected AtlasJarvisAugmentor $atlasAugmentor,
    ) {}

    public function recommendations(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        return response()->json([
            'data' => $this->gateway->listRecommendations($workspaceId),
        ]);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $result = $this->gateway->acceptRecommendation($id);

        $this->eventStore->append(
            tenantId: $workspaceId,
            aggregateType: 'recommendation',
            aggregateId: $id,
            eventType: 'recommendation.accepted',
            payload: $result,
        );

        return response()->json($result);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $result = $this->gateway->rejectRecommendation($id);

        $this->eventStore->append(
            tenantId: $workspaceId,
            aggregateType: 'recommendation',
            aggregateId: $id,
            eventType: 'recommendation.rejected',
            payload: $result,
        );

        return response()->json($result);
    }

    public function autonomy(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->gateway->getAutonomy($this->workspaceId($request)),
        ]);
    }

    public function updateAutonomy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'autonomy_level' => 'required|integer|min:1|max:3',
            'auto_execute_threshold' => 'nullable|numeric|min:0|max:1',
            'rollback_threshold' => 'nullable|numeric|min:0|max:1',
        ]);

        return response()->json([
            'data' => $this->gateway->updateAutonomy($this->workspaceId($request), $validated),
        ]);
    }

    public function weeklyReview(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $tenant = $request->attributes->get('tenant');
        $recommendations = $this->gateway->listRecommendations($workspaceId);
        $autonomy = $this->gateway->getAutonomy($workspaceId);

        $pending = array_values(array_filter($recommendations, fn ($r) => ($r['status'] ?? '') === 'pending'));
        $accepted = array_values(array_filter($recommendations, fn ($r) => ($r['status'] ?? '') === 'accepted'));

        $briefing = $this->atlasAugmentor->buildOperatorBriefing(
            $workspaceId,
            $pending,
            $accepted,
            $autonomy,
            $tenant?->plan,
        );

        return response()->json([
            'data' => [
                'headline' => 'Your 5-minute weekly review',
                'summary' => [
                    'pending_count' => count($pending),
                    'accepted_count' => count($accepted),
                    'autonomy_level' => $autonomy['autonomy_level'] ?? 1,
                ],
                'recommendations' => array_slice($recommendations, 0, 10),
                'autonomy' => $autonomy,
                'atlas_briefing' => $briefing,
            ],
        ]);
    }

    private function workspaceId(Request $request): string
    {
        return (string) ($request->attributes->get('tenant_id') ?? $request->user()?->tenant_id);
    }
}
