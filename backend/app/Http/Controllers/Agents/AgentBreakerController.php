<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\TenantAgentState;
use App\Services\Agents\AgentCircuitBreaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The circuit breaker as one endpoint (plan D8 #6): "Pause everything",
 * "Pause Richard", "Stop sends but keep drafting", resume — from God's Eye,
 * a skill card, or a phone link.
 *
 *   GET  /api/agents/breaker              → states + presets
 *   POST /api/agents/breaker              → {action: pause|demote|resume, scope, scope_id?, reason?, resume_in_minutes?}
 *                                           or {preset: pause_everything|stop_sends|pause_agent|pause_skill, scope_id?}
 */
class AgentBreakerController extends Controller
{
    public function __construct(private readonly AgentCircuitBreaker $breaker) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        return response()->json(['data' => $this->envelope($tenantId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $v = $request->validate([
            'action' => 'required_without:preset|string|in:pause,demote,resume',
            'preset' => 'sometimes|string|in:'.implode(',', array_keys(AgentCircuitBreaker::PRESETS)),
            'scope' => 'sometimes|string|in:'.implode(',', TenantAgentState::SCOPES),
            'scope_id' => 'sometimes|nullable|string|max:64',
            'reason' => 'sometimes|nullable|string|max:500',
            'resume_in_minutes' => 'sometimes|nullable|integer|min:1|max:43200',
        ]);

        $action = (string) ($v['action'] ?? 'pause');
        $scope = (string) ($v['scope'] ?? TenantAgentState::SCOPE_TENANT);
        $scopeId = $v['scope_id'] ?? null;

        if (! empty($v['preset'])) {
            $preset = AgentCircuitBreaker::PRESETS[$v['preset']];
            $scope = $preset['scope'];
            $scopeId = $preset['scope_id'] ?? $scopeId;
        }

        $user = $request->user();
        $reason = trim((string) ($v['reason'] ?? '')) !== '' ? trim((string) $v['reason']) : ucfirst($action).'d by '.($user->name ?? 'a human');
        $resumeAt = ! empty($v['resume_in_minutes']) ? now()->addMinutes((int) $v['resume_in_minutes']) : null;

        try {
            $state = match ($action) {
                'pause' => $this->breaker->pause($tenantId, $scope, $scopeId, $reason, TenantAgentState::TRIPPED_BY_HUMAN, $resumeAt, (string) $user->id),
                'demote' => $this->breaker->demote($tenantId, $scope, $scopeId, $reason, TenantAgentState::TRIPPED_BY_HUMAN, $resumeAt, (string) $user->id),
                default => $this->breaker->resume($tenantId, $scope, $scopeId, (string) $user->id),
            };
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->envelope($tenantId) + ['changed' => $state?->toEnvelope()]]);
    }

    /** @return array<string, mixed> */
    private function envelope(string $tenantId): array
    {
        $states = $this->breaker->states($tenantId);
        $tripped = array_values(array_filter($states, fn (array $s): bool => $s['state'] !== TenantAgentState::STATE_RUNNING));

        return [
            'states' => $states,
            'tripped' => $tripped,
            'paused_everything' => (bool) array_filter($tripped, fn (array $s): bool => $s['scope'] === TenantAgentState::SCOPE_TENANT && $s['state'] === TenantAgentState::STATE_PAUSED),
            'sends_blocked' => $this->breaker->isPaused($tenantId, null, null, 'send') !== null,
            'presets' => array_map(fn (string $key, array $p): array => ['key' => $key] + $p, array_keys(AgentCircuitBreaker::PRESETS), AgentCircuitBreaker::PRESETS),
        ];
    }
}
