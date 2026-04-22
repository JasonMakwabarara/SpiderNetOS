<?php

namespace App\Http\Controllers;

use App\Services\AtlasInteractionLogger;
use App\Services\EventStore;
use App\Services\FeatureFlag;
use App\Services\MetaPlanner;
use App\Services\Onboarding\OnboardingPolicy;
use App\Services\PromptEnhancer;
use App\Services\TransformationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AtlasController extends Controller
{
    private EventStore $eventStore;
    private MetaPlanner $metaPlanner;
    private TransformationEngine $transformationEngine;
    private AtlasInteractionLogger $interactionLogger;
    private OnboardingPolicy $onboardingPolicy;

    public function __construct(
        EventStore $eventStore,
        MetaPlanner $metaPlanner,
        TransformationEngine $transformationEngine,
        AtlasInteractionLogger $interactionLogger,
        OnboardingPolicy $onboardingPolicy,
    ) {
        $this->eventStore = $eventStore;
        $this->metaPlanner = $metaPlanner;
        $this->transformationEngine = $transformationEngine;
        $this->interactionLogger = $interactionLogger;
        $this->onboardingPolicy = $onboardingPolicy;
    }

    /**
     * POST /api/atlas/enhance-prompt
     *
     * Transforms a terse user prompt into a richer, structured instruction.
     * Gated by feature flag `atlas.enhance_prompt` (default on).
     *
     * Budget:
     *   - p95 target < 1.5s when inference plane is reachable.
     *   - Deterministic fallback returns in < 20ms.
     */
    public function enhancePrompt(Request $request, PromptEnhancer $enhancer): JsonResponse
    {
        if (!FeatureFlag::on('atlas.enhance_prompt')) {
            return response()->json([
                'error'   => 'enhance_prompt_disabled',
                'message' => 'The Enhance Prompt feature is currently disabled for this tenant.',
            ], 503);
        }

        $data = $request->validate([
            'prompt'   => 'required|string|min:1|max:4000',
            'mode'     => 'sometimes|string|in:concise,balanced,deep',
            'surface'  => 'sometimes|string|in:atlas_chat,agent_builder,flow_builder,generic',
            'audience' => 'sometimes|string|in:user,admin,super_admin',
            'tone'     => 'sometimes|string|in:neutral,concise,deep',
        ]);

        $started = microtime(true);

        $result = $enhancer->enhance($data['prompt'], [
            'mode'     => $data['mode']     ?? 'balanced',
            'surface'  => $data['surface']  ?? 'generic',
            'audience' => $data['audience'] ?? 'user',
            'tone'     => $data['tone']     ?? 'neutral',
        ]);

        $result['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
        return response()->json($result);
    }

    /**
     * Chat with Atlas — accept message, dispatch through MetaPlanner, return response.
     *
     * v1 Response Contract (B1):
     *   future_state, value, emotional_shift, action_summary, details (optional)
     *
     * Hard Rule #3: Atlas UI and Atlas Agent NEVER share runtime memory.
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'session_id' => 'nullable|string',
            'style' => 'sometimes|string|in:concise,balanced,emotional,analytical,directive',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->user()->id;
        $message = $request->input('message');
        $sessionId = $request->input('session_id', (string) Str::uuid());
        $style = $request->input('style', config('services.spidernet.atlas_default_style', 'balanced'));
        $interactionId = (string) Str::uuid();
        $startedAt = microtime(true);

        // Record user message in event_log (ephemeral session — Hard Rule #3)
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'atlas_session',
            aggregateId: $sessionId,
            eventType: 'atlas.message.received',
            payload: [
                'user_id' => $userId,
                'role' => 'user',
                'content' => $message,
                'interaction_id' => $interactionId,
            ],
        );

        // Check for onboarding policy override (soft gate)
        $overridePolicy = $this->onboardingPolicy->overrideFor($request->user());
        $onboardingState = $request->user()->tenant?->onboarding ?? [];

        // Emit policy.exposed event for observability baseline
        if ($overridePolicy !== null) {
            Log::info('[Atlas] Onboarding policy exposed', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'policy' => $overridePolicy,
                'onboarding_steps_completed' => count($onboardingState),
            ]);
        }

        // Process through MetaPlanner with context
        $result = $this->metaPlanner->processAtlasRequest(
            tenantId: $tenantId,
            userId: $userId,
            message: $message,
            sessionId: $sessionId,
            context: [
                'override_policy' => $overridePolicy,
                'onboarding_state' => $onboardingState,
            ],
        );

        // Parse intent for TransformationEngine
        $parsedIntent = $this->parseIntentForTransformation($result, $message);

        // Transform execution result into 5-field contract
        $transformed = $this->transformationEngine->transform(
            $parsedIntent,
            $this->buildExecutionResult($result),
            $style,
        );

        $contract = $transformed['contract'];
        $metaIntent = $result['ast']['type'] ?? ($parsedIntent['task_type'] ?? 'chat');

        // Handle blocked requests: contract-compliant refusal
        if (($result['status'] ?? '') === 'blocked') {
            $contract = [
                'future_state'    => 'Your request is paused while limits clear.',
                'value'           => 'You avoid exceeding your current budget.',
                'emotional_shift' => 'No surprise overages, full control preserved.',
                'action_summary'  => 'I held the request to protect your constraints.',
                'details'         => $result['reason'] ?? null,
            ];
        }

        // Record Atlas response in event_log
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'atlas_session',
            aggregateId: $sessionId,
            eventType: 'atlas.message.sent',
            payload: [
                'role' => 'atlas',
                'contract' => $contract,
                'interaction_id' => $interactionId,
                'style' => $transformed['style'],
                'source' => $transformed['source'],
            ],
        );

        // Async log atlas_interactions row for TS/RL (B5)
        $this->interactionLogger->record([
            'interaction_id' => $interactionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'user_input' => $message,
            'parsed_intent' => $parsedIntent,
            'atlas_response' => $contract,
            'execution_result' => $this->buildExecutionResult($result),
            'generation' => [
                'style' => $transformed['style'],
                'source' => $transformed['source'],
                'violations' => $transformed['violations'] ?? [],
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ],
        ]);

        return response()->json([
            'contract_version' => '1',
            'session_id' => $sessionId,
            'interaction_id' => $interactionId,
            'message' => [
                'id' => (string) Str::uuid(),
                'role' => 'atlas',
                'contract' => $contract,
                'timestamp' => now()->toIso8601String(),
                'metadata' => [
                    'intent' => $metaIntent,
                    'agent_used' => $result['agent_id'] ?? 'atlas',
                    'status' => $result['status'] ?? 'received',
                    'style' => $transformed['style'],
                    'source' => $transformed['source'],
                ],
            ],
            'ast' => $result['ast'] ?? null,
            'cost_status' => $result['cost_status'] ?? null,
        ]);
    }

    /**
     * Translate planner result + user message into a parsed_intent structure.
     */
    private function parseIntentForTransformation(array $result, string $message): array
    {
        $type = $result['ast']['type'] ?? 'chat';

        $taskType = match ($type) {
            'create_flow', 'execute' => 'automation',
            'analyze_data', 'query_status' => 'analysis',
            'monitor' => 'monitoring',
            default => 'chat',
        };

        return [
            'desired_future' => '',
            'pain_points' => '',
            'functional_goal' => $message,
            'emotional_goal' => '',
            'task_type' => $taskType,
        ];
    }

    /**
     * Build a stable execution_result structure for the transformation engine.
     */
    private function buildExecutionResult(array $result): array
    {
        return [
            'status' => $result['status'] ?? 'received',
            'agent_used' => $result['agent_id'] ?? 'atlas',
            'cost_status' => $result['cost_status'] ?? null,
            'metrics' => [
                // Intentionally empty for v1 defaults; actual execution metrics
                // are populated by downstream agents and joined later via events.
            ],
        ];
    }

    /**
     * POST /api/atlas/events
     *
     * Async instrumentation endpoint (B5). Frontend emits behavioral events
     * tied to an interaction_id. These never block rendering.
     *
     * Body:
     *   interaction_id: UUID
     *   event_type: RESPONSE_VIEWED | DETAILS_EXPANDED | ACTION_ACCEPTED | TIME_SPENT | FOLLOW_UP | RATING
     *   data: object (event-specific fields)
     */
    public function events(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'interaction_id' => 'required|string|uuid',
            'event_type' => 'required|string|in:RESPONSE_VIEWED,DETAILS_EXPANDED,ACTION_ACCEPTED,TIME_SPENT,FOLLOW_UP,RATING',
            'data' => 'sometimes|array',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $this->interactionLogger->applyEvent(
            (string) $tenantId,
            (string) $payload['interaction_id'],
            (string) $payload['event_type'],
            (array) ($payload['data'] ?? []),
        );

        return response()->json(['accepted' => true], 202);
    }

    /**
     * Preview a plan without executing it.
     */
    public function plan(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'session_id' => 'required|string',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        // Parse to AST
        $ast = $this->metaPlanner->parseCommandToAst($request->input('message'));

        return response()->json([
            'session_id' => $request->input('session_id'),
            'plan' => [
                'id' => (string) Str::uuid(),
                'intent' => $ast['type'] ?? 'chat',
                'status' => 'draft',
                'tasks' => $this->buildPlanTasks($ast),
                'created_at' => now()->toIso8601String(),
            ],
            'preview' => "Plan: {$ast['type']} with " . count($this->buildPlanTasks($ast)) . " tasks",
        ]);
    }

    /**
     * Execute an approved plan.
     */
    public function executePlan(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|string',
            'session_id' => 'required|string',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->user()->id;

        // For now, dispatch the plan through MetaPlanner
        return response()->json([
            'plan_id' => $request->input('plan_id'),
            'status' => 'executing',
            'message' => 'Plan execution started',
        ]);
    }

    /**
     * Cancel a running plan.
     */
    public function cancelPlan(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|string',
            'session_id' => 'required|string',
        ]);

        return response()->json([
            'plan_id' => $request->input('plan_id'),
            'status' => 'cancelled',
            'message' => 'Plan cancelled',
        ]);
    }

    /**
     * Build task list from AST for plan preview.
     */
    private function buildPlanTasks(array $ast): array
    {
        $type = $ast['type'] ?? 'chat';

        return match ($type) {
            'create_flow' => [
                ['id' => '1', 'label' => 'Parse requirements', 'agent_id' => 'forge', 'status' => 'pending'],
                ['id' => '2', 'label' => 'Design DAG structure', 'agent_id' => 'forge', 'status' => 'pending'],
                ['id' => '3', 'label' => 'Validate flow', 'agent_id' => 'forge', 'status' => 'pending'],
                ['id' => '4', 'label' => 'Save to workspace', 'agent_id' => 'forge', 'status' => 'pending'],
            ],
            'execute' => [
                ['id' => '1', 'label' => 'Load flow DAG', 'agent_id' => 'nexus', 'status' => 'pending'],
                ['id' => '2', 'label' => 'Execute nodes', 'agent_id' => 'nexus', 'status' => 'pending'],
                ['id' => '3', 'label' => 'Collect results', 'agent_id' => 'nexus', 'status' => 'pending'],
            ],
            'query_status' => [
                ['id' => '1', 'label' => 'Check system health', 'agent_id' => 'sentinel', 'status' => 'pending'],
            ],
            default => [
                ['id' => '1', 'label' => 'Process message', 'agent_id' => 'atlas', 'status' => 'pending'],
            ],
        };
    }
}
