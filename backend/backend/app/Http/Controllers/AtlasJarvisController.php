<?php

namespace App\Http\Controllers;

use App\Services\AtlasJarvisAugmentor;
use App\Services\FeatureFlag;
use App\Services\OpenJarvisGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OpenJarvis × Atlas AI endpoints for AIOS operators.
 */
class AtlasJarvisController extends Controller
{
    public function __construct(
        private readonly OpenJarvisGateway $gateway,
        private readonly AtlasJarvisAugmentor $augmentor,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'enabled' => FeatureFlag::on('atlas.openjarvis'),
            'bridge' => $this->gateway->health(),
        ]);
    }

    public function agents(): JsonResponse
    {
        return response()->json(['data' => $this->gateway->listAgents()]);
    }

    public function skills(): JsonResponse
    {
        return response()->json(['data' => $this->gateway->listSkills()]);
    }

    public function ask(Request $request): JsonResponse
    {
        if (!FeatureFlag::on('atlas.openjarvis', $request->attributes->get('tenant_id'))) {
            return response()->json(['error' => 'openjarvis_disabled'], 503);
        }

        $data = $request->validate([
            'message' => 'required|string|max:8000',
            'agent' => 'sometimes|string|in:orchestrator,deep_research,native_react,code_assistant,monitor_operative,morning_digest,simple',
            'session_id' => 'nullable|string',
            'skills' => 'sometimes|array',
            'skills.*' => 'string|max:128',
        ]);

        $tenantId = (string) $request->attributes->get('tenant_id');
        $sessionId = $data['session_id'] ?? (string) \Illuminate\Support\Str::uuid();

        $augmented = $this->augmentor->augment(
            tenantId: $tenantId,
            userId: (string) $request->user()->id,
            sessionId: $sessionId,
            message: $data['message'],
            forcedAgent: $data['agent'] ?? null,
            skills: $data['skills'] ?? [],
        );

        if ($augmented === null) {
            return response()->json(['ok' => false, 'reason' => 'augmentation_unavailable'], 503);
        }

        return response()->json([
            'ok' => true,
            'session_id' => $sessionId,
            'data' => $augmented['jarvis'],
        ]);
    }

    public function research(Request $request): JsonResponse
    {
        if (!FeatureFlag::on('atlas.openjarvis', $request->attributes->get('tenant_id'))) {
            return response()->json(['error' => 'openjarvis_disabled'], 503);
        }

        $data = $request->validate([
            'query' => 'required|string|max:4000',
            'max_hops' => 'sometimes|integer|min:1|max:8',
        ]);

        $tenantId = (string) $request->attributes->get('tenant_id');
        $result = $this->gateway->research(
            $data['query'],
            $tenantId,
            (int) ($data['max_hops'] ?? 3),
        );

        return response()->json($result);
    }
}
