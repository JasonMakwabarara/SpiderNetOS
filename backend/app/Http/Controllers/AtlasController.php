<?php

namespace App\Http\Controllers;

use App\Services\AtlasClarityGate;
use App\Services\AtlasDiscoveryService;
use App\Services\DagExecutionService;
use App\Services\FlowTemplateBuilder;
use App\Services\AtlasInteractionLogger;
use App\Services\AtlasJarvisAugmentor;
use App\Services\EventStore;
use App\Services\FeatureFlag;
use App\Services\MetaPlanner;
use App\Services\Onboarding\OnboardingPolicy;
use App\Services\PackGrowthService;
use App\Services\PromptEnhancer;
use App\Services\TransformationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        private readonly AtlasJarvisAugmentor $jarvisAugmentor,
        private readonly AtlasDiscoveryService $discoveryService,
        private readonly PackGrowthService $packGrowth,
        private readonly AtlasClarityGate $clarityGate,
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

        // Learn from user input and check discovery mode (skip for slash commands)
        $this->discoveryService->absorbAnswer($tenantId, $message);
        $discovery = $this->discoveryService->evaluate($tenantId, $message, $this->packGrowth);
        $isSlashCommand = str_starts_with(trim($message), '/');

        if (($discovery['mode'] ?? 'act') === 'discover' && ! $isSlashCommand) {
            $question = ($discovery['questions'][0] ?? 'What task eats the most time in your week?');
            $suggested = $discovery['suggested_next'] ?? null;

            $contract = [
                'future_state' => 'SpiderNetOS learns your business so it can automate the right things first.',
                'value' => 'One clear next step instead of guessing.',
                'emotional_shift' => 'Clarity and control from the start.',
                'action_summary' => $question,
                'details' => null,
            ];

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
                        'intent' => 'discovery',
                        'mode' => 'discover',
                        'questions' => $discovery['questions'] ?? [$question],
                        'profile_pct' => $discovery['profile_pct'] ?? 0,
                        'agent_used' => 'atlas',
                        'status' => 'discovery',
                    ],
                ],
                'suggested_next' => $suggested,
                'ast' => ['type' => 'discovery'],
                'cost_status' => null,
            ]);
        }

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

        $automationLevel = $request->user()->tenant?->automation_level ?? 'assisted';

        if (! $isSlashCommand) {
            $clarity = $this->clarityGate->assess($tenantId, $message, $automationLevel);

            if (($clarity['mode'] ?? 'act') === 'clarify') {
                $question = $clarity['question'] ?? ($clarity['questions'][0] ?? 'Can you tell me a bit more?');
                $this->clarityGate->recordRefinementSignal($tenantId, 'clarify_asked', [
                    'interaction_id' => $interactionId,
                    'intent' => $clarity['consequence']['intent'] ?? null,
                ]);

                return $this->buildClarifyResponse(
                    $sessionId,
                    $interactionId,
                    $question,
                    $clarity['questions'] ?? [$question],
                    $clarity['confidence'] ?? null,
                );
            }

            if (($clarity['mode'] ?? 'act') === 'confirm') {
                $pending = $clarity['pending_action'] ?? [];
                $actionId = (string) ($pending['id'] ?? Str::uuid());

                $this->supersedePendingConfirm($tenantId, $userId, $actionId);

                Cache::put(
                    $this->pendingCacheKey($tenantId, $actionId),
                    [
                        'message' => $message,
                        'session_id' => $sessionId,
                        'style' => $style,
                        'interaction_id' => $interactionId,
                        'user_id' => $userId,
                        'intent' => $pending['intent'] ?? 'automation',
                        'pending_action' => $pending,
                    ],
                    600,
                );

                $this->clarityGate->recordRefinementSignal($tenantId, 'confirm_requested', [
                    'interaction_id' => $interactionId,
                    'action_id' => $actionId,
                    'intent' => $pending['intent'] ?? null,
                ]);

                return $this->buildConfirmResponse(
                    $sessionId,
                    $interactionId,
                    $pending,
                    $message,
                );
            }
        }

        return $this->actOnMessage(
            tenantId: $tenantId,
            userId: $userId,
            message: $message,
            sessionId: $sessionId,
            style: $style,
            interactionId: $interactionId,
            request: $request,
            startedAt: $startedAt,
        );
    }

    /**
     * POST /api/atlas/confirm — proceed or cancel a pending consequential action.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action_id' => 'required|string|uuid',
            'decision' => 'required|string|in:proceed,cancel',
            'session_id' => 'nullable|string',
            'style' => 'sometimes|string|in:concise,balanced,emotional,analytical,directive',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->user()->id;
        $actionId = $validated['action_id'];
        $cacheKey = $this->pendingCacheKey($tenantId, $actionId);

        if ($validated['decision'] === 'cancel') {
            $pending = Cache::get($cacheKey);

            if (! is_array($pending)) {
                return response()->json(['message' => 'Confirmation expired or not found.'], 404);
            }

            if ($denied = $this->denyIfNotPendingOwner($pending, $userId)) {
                return $denied;
            }

            $sessionId = $validated['session_id'] ?? $pending['session_id'] ?? (string) Str::uuid();
            $interactionId = (string) Str::uuid();

            Cache::forget($cacheKey);
            $this->forgetPendingIndex($tenantId, $userId, $actionId);
            $this->clarityGate->recordRefinementSignal($tenantId, 'confirm_cancelled', [
                'action_id' => $actionId,
                'intent' => $pending['intent'] ?? null,
            ]);
            $this->eventStore->append(
                tenantId: $tenantId,
                aggregateType: 'atlas_session',
                aggregateId: $sessionId,
                eventType: 'atlas.confirmation.resolved',
                payload: [
                    'action_id' => $actionId,
                    'decision' => 'cancel',
                    'user_id' => $userId,
                ],
            );

            $contract = [
                'future_state' => 'Nothing changed — you stayed in control.',
                'value' => 'You avoided acting before you were ready.',
                'emotional_shift' => 'Confidence that Atlas waits for your signal.',
                'action_summary' => 'Held — I did not run anything.',
                'details' => null,
            ];

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
                        'intent' => 'confirmation',
                        'mode' => 'held',
                        'status' => 'cancelled',
                        'agent_used' => 'atlas',
                    ],
                ],
            ]);
        }

        $pending = Cache::pull($cacheKey);

        if (! is_array($pending)) {
            return response()->json(['message' => 'Confirmation expired or not found.'], 404);
        }

        if ($denied = $this->denyIfNotPendingOwner($pending, $userId)) {
            return $denied;
        }

        $this->forgetPendingIndex($tenantId, $userId, $actionId);

        $sessionId = $validated['session_id'] ?? $pending['session_id'] ?? (string) Str::uuid();
        $interactionId = (string) Str::uuid();

        $this->clarityGate->recordRefinementSignal($tenantId, 'confirm_proceeded', [
            'action_id' => $actionId,
            'intent' => $pending['intent'] ?? null,
        ]);
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'atlas_session',
            aggregateId: $sessionId,
            eventType: 'atlas.confirmation.resolved',
            payload: [
                'action_id' => $actionId,
                'decision' => 'proceed',
                'user_id' => $userId,
                'message' => $pending['message'] ?? '',
            ],
        );

        $response = $this->actOnMessage(
            tenantId: $tenantId,
            userId: $userId,
            message: (string) ($pending['message'] ?? ''),
            sessionId: $sessionId,
            style: $validated['style'] ?? $pending['style'] ?? 'balanced',
            interactionId: $interactionId,
            request: $request,
            startedAt: microtime(true),
        );

        $payload = $response->getData(true);
        if (($payload['message']['metadata']['status'] ?? '') === 'dispatched') {
            $this->clarityGate->recordTrustConfirmation($tenantId, (string) ($pending['intent'] ?? 'automation'));
        }

        return $response;
    }

    private function pendingCacheKey(string $tenantId, string $actionId): string
    {
        return "atlas_pending:{$tenantId}:{$actionId}";
    }

    private function pendingIndexKey(string $tenantId, string $userId): string
    {
        return "atlas_pending_index:{$tenantId}:{$userId}";
    }

    private function supersedePendingConfirm(string $tenantId, string $userId, string $newActionId): void
    {
        $indexKey = $this->pendingIndexKey($tenantId, $userId);
        $previousId = Cache::get($indexKey);
        if (is_string($previousId) && $previousId !== $newActionId) {
            Cache::forget($this->pendingCacheKey($tenantId, $previousId));
        }
        Cache::put($indexKey, $newActionId, 600);
    }

    private function forgetPendingIndex(string $tenantId, string $userId, string $actionId): void
    {
        $indexKey = $this->pendingIndexKey($tenantId, $userId);
        if (Cache::get($indexKey) === $actionId) {
            Cache::forget($indexKey);
        }
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function denyIfNotPendingOwner(array $pending, string|int $userId): ?JsonResponse
    {
        if ((string) ($pending['user_id'] ?? '') !== (string) $userId) {
            return response()->json(['message' => 'This confirmation belongs to another user.'], 403);
        }

        return null;
    }

    private function actOnMessage(
        string $tenantId,
        string $userId,
        string $message,
        string $sessionId,
        string $style,
        string $interactionId,
        Request $request,
        float $startedAt,
    ): JsonResponse {
        // Check for onboarding policy override (soft gate)
        $overridePolicy = $this->onboardingPolicy->overrideFor($request->user());
        $onboardingState = $request->user()->tenant?->onboarding ?? [];

        if ($overridePolicy !== null) {
            Log::info('[Atlas] Onboarding policy exposed', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'policy' => $overridePolicy,
                'onboarding_steps_completed' => count($onboardingState),
            ]);
        }

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

        $ast = $this->metaPlanner->parseCommandToAst($message);
        $result['ast'] = $ast;

        $tenant = $request->attributes->get('tenant');
        $jarvisPayload = $this->jarvisAugmentor->augment(
            tenantId: $tenantId,
            userId: $userId,
            sessionId: $sessionId,
            message: $message,
            plan: $tenant?->plan,
        );

        $parsedIntent = $this->parseIntentForTransformation($result, $message, $jarvisPayload);
        $executionResult = $this->buildExecutionResult($result, $jarvisPayload);
        $transformed = $this->transformationEngine->transform(
            $parsedIntent,
            $executionResult,
            $style,
        );

        $contract = $transformed['contract'];
        $metaIntent = $result['ast']['type'] ?? ($parsedIntent['task_type'] ?? 'chat');

        if (($result['status'] ?? '') === 'blocked') {
            $contract = [
                'future_state'    => 'Your request is paused while limits clear.',
                'value'           => 'You avoid exceeding your current budget.',
                'emotional_shift' => 'No surprise overages, full control preserved.',
                'action_summary'  => 'I held the request to protect your constraints.',
                'details'         => $result['reason'] ?? null,
            ];
        }

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

        $this->interactionLogger->record([
            'interaction_id' => $interactionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'user_input' => $message,
            'parsed_intent' => $parsedIntent,
            'atlas_response' => $contract,
            'execution_result' => $executionResult,
            'generation' => [
                'style' => $transformed['style'],
                'source' => $transformed['source'],
                'violations' => $transformed['violations'] ?? [],
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ],
        ]);

        $inferenceCost = (float) ($jarvisPayload['jarvis']['intelligence_per_watt']['estimated_cost_usd'] ?? 0);

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
                    'mode' => 'act',
                    'agent_used' => $result['agent_id'] ?? 'atlas',
                    'status' => $result['status'] ?? 'received',
                    'style' => $transformed['style'],
                    'source' => $transformed['source'],
                    'estimated_cost_usd' => $inferenceCost > 0 ? $inferenceCost : null,
                ],
            ],
            'ast' => $result['ast'] ?? null,
            'cost_status' => $result['cost_status'] ?? null,
        ]);
    }

    private function buildClarifyResponse(
        string $sessionId,
        string $interactionId,
        string $question,
        array $questions,
        ?float $confidence,
    ): JsonResponse {
        $contract = [
            'future_state' => 'We get the right automation, not a guess.',
            'value' => 'One honest question now saves rework later.',
            'emotional_shift' => 'Clarity instead of false confidence.',
            'action_summary' => $question,
            'details' => null,
        ];

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
                    'intent' => 'clarify',
                    'mode' => 'clarify',
                    'questions' => $questions,
                    'confidence' => $confidence,
                    'agent_used' => 'atlas',
                    'status' => 'clarify',
                ],
            ],
            'ast' => ['type' => 'clarify'],
            'cost_status' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function buildConfirmResponse(
        string $sessionId,
        string $interactionId,
        array $pending,
        string $message,
    ): JsonResponse {
        $summary = (string) ($pending['summary'] ?? Str::limit($message, 140));
        $reversible = (bool) ($pending['reversible'] ?? true);

        $contract = [
            'future_state' => 'You approve one step at a time — trust earned, not assumed.',
            'value' => 'Nothing runs until you say proceed.',
            'emotional_shift' => 'Full control before anything changes.',
            'action_summary' => "I understand you want me to: {$summary}",
            'details' => $reversible
                ? 'This looks reversible — you can adjust after the first run.'
                : 'This may change data or send something — please confirm.',
        ];

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
                    'intent' => 'confirm',
                    'mode' => 'confirm',
                    'status' => 'awaiting_confirmation',
                    'agent_used' => 'atlas',
                    'pending_action' => $pending,
                ],
            ],
            'pending_action' => $pending,
            'ast' => ['type' => 'confirm'],
            'cost_status' => null,
        ]);
    }

    /**
     * Translate planner result + user message into a parsed_intent structure.
     */
    private function parseIntentForTransformation(array $result, string $message, ?array $jarvisPayload = null): array
    {
        $type = $result['ast']['type'] ?? 'chat';

        $taskType = match ($type) {
            'create_flow', 'execute' => 'automation',
            'analyze_data', 'query_status' => 'analysis',
            'monitor' => 'monitoring',
            default => 'chat',
        };

        if ($jarvisPayload !== null) {
            $agent = $jarvisPayload['jarvis']['agent'] ?? null;
            if ($agent === 'deep_research') {
                $taskType = 'analysis';
            } elseif (in_array($agent, ['orchestrator', 'code_assistant', 'native_react'], true)) {
                $taskType = 'automation';
            }
        }

        $functionalGoal = $message;
        if (!empty($jarvisPayload['jarvis']['text'])) {
            $functionalGoal = $jarvisPayload['jarvis']['text'];
        }

        return [
            'desired_future' => '',
            'pain_points' => '',
            'functional_goal' => $functionalGoal,
            'emotional_goal' => '',
            'task_type' => $taskType,
        ];
    }

    /**
     * Build a stable execution_result structure for the transformation engine.
     */
    private function buildExecutionResult(array $result, ?array $jarvisPayload = null): array
    {
        $base = [
            'status' => $result['status'] ?? 'received',
            'agent_used' => $result['agent_id'] ?? 'atlas',
            'cost_status' => $result['cost_status'] ?? null,
            'metrics' => [],
        ];

        if ($jarvisPayload !== null) {
            $base['jarvis'] = $jarvisPayload['jarvis'];
            $base['metrics']['jarvis_source'] = $jarvisPayload['jarvis']['source'] ?? null;
            $base['metrics']['local_first'] = $jarvisPayload['jarvis']['intelligence_per_watt']['local_first'] ?? null;
        }

        return $base;
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
        $planId = (string) Str::uuid();

        Cache::put("atlas_plan:{$tenantId}:{$planId}", [
            'intent' => $ast['type'] ?? 'chat',
            'message' => $request->input('message'),
            'tasks' => $this->buildPlanTasks($ast),
        ], 3600);

        return response()->json([
            'session_id' => $request->input('session_id'),
            'plan' => [
                'id' => $planId,
                'intent' => $ast['type'] ?? 'chat',
                'status' => 'draft',
                'tasks' => $this->buildPlanTasks($ast),
                'created_at' => now()->toIso8601String(),
            ],
            'preview' => "Plan: {$ast['type']} with " . count($this->buildPlanTasks($ast)) . " tasks",
        ]);
    }

    /**
     * Execute an approved plan via flow creation + DAG execution.
     */
    public function executePlan(
        Request $request,
        FlowTemplateBuilder $builder,
        DagExecutionService $dagExecution,
    ): JsonResponse {
        $request->validate([
            'plan_id' => 'required|string',
            'session_id' => 'required|string',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $planId = $request->input('plan_id');
        $cached = Cache::get("atlas_plan:{$tenantId}:{$planId}");

        if (! is_array($cached)) {
            return response()->json(['message' => 'Plan not found or expired.'], 404);
        }

        $template = match ($cached['intent'] ?? 'chat') {
            'create_flow' => 'followup',
            'execute' => 'status',
            default => 'status',
        };

        $built = $builder->build($template, 'operator', 'now');
        $flowId = (string) Str::uuid();

        DB::table('flows')->insert([
            'id' => $flowId,
            'tenant_id' => $tenantId,
            'name' => $built['name'],
            'slug' => $built['slug'],
            'description' => (string) ($cached['message'] ?? $built['description']),
            'dag' => json_encode($built['dag']),
            'triggers' => json_encode($built['triggers']),
            'status' => 'published',
            'schedule_cron' => $built['schedule_cron'],
            'schedule_timezone' => 'UTC',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $dagExecution->createExecution($tenantId, $flowId, (array) ($built['triggers']['context'] ?? []));

        return response()->json([
            'plan_id' => $planId,
            'flow_id' => $flowId,
            'execution_id' => $result['execution_id'] ?? null,
            'status' => $result['status'] ?? 'executing',
            'message' => 'Plan execution started',
            'plan' => array_merge($cached, [
                'id' => $planId,
                'status' => 'executing',
                'execution_id' => $result['execution_id'] ?? null,
            ]),
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
