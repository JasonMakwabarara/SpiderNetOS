<?php

namespace App\Http\Controllers;

use App\Services\IntelligenceGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side proxy to V2 intelligence services.
 * Cockpit and landing should prefer these routes over calling V2 directly.
 */
class IntelligenceProxyController extends Controller
{
    public function __construct(protected IntelligenceGateway $gateway) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'layer' => 'v2-intelligence',
            'gateway' => $this->gateway->health(),
        ]);
    }

    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_payload' => 'required',
            'workspace_id' => 'nullable|string',
            'tenant_id' => 'nullable|string',
        ]);

        $workspaceId = $validated['workspace_id']
            ?? $validated['tenant_id']
            ?? $request->attributes->get('tenant_id')
            ?? $request->user()?->tenant_id;

        if (! $workspaceId) {
            return response()->json([
                'ok' => false,
                'error' => 'workspace_id or tenant_id is required',
            ], 422);
        }

        $payload = $this->normalizeEventPayload($validated['event_payload']);

        return response()->json(
            $this->gateway->evaluate($payload, (string) $workspaceId)
        );
    }

    public function coordinateCycle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => 'nullable|string',
            'tenant_id' => 'nullable|string',
            'list_id' => 'nullable|string',
        ]);

        $workspaceId = $validated['workspace_id']
            ?? $validated['tenant_id']
            ?? $request->attributes->get('tenant_id')
            ?? $request->user()?->tenant_id;

        if (! $workspaceId) {
            return response()->json([
                'ok' => false,
                'error' => 'workspace_id or tenant_id is required',
            ], 422);
        }

        return response()->json(
            $this->gateway->coordinateAtlasCycle(
                (string) $workspaceId,
                $validated['list_id'] ?? null
            )
        );
    }

    public function compileDag(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dag' => 'required|array',
        ]);

        return response()->json($this->gateway->compileDag($validated['dag']));
    }

    /**
     * Accept array payloads or JSON/string scalars from clients.
     *
     * @return array<string, mixed>
     */
    private function normalizeEventPayload(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return ['value' => $raw];
        }

        if (is_object($raw)) {
            return (array) $raw;
        }

        return ['value' => $raw];
    }
}
