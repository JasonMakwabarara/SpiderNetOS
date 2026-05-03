<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class MetaPlanner
{
    private CostGovernor $costGovernor;
    private EventStore $eventStore;
    
    public function __construct(
        CostGovernor $costGovernor,
        EventStore $eventStore
    ) {
        $this->costGovernor = $costGovernor;
        $this->eventStore = $eventStore;
    }
    
    /**
     * Hard Rule #2: Meta-Planner is the ONLY decision authority
     * All agent dispatch goes through here. Agents cannot call other agents directly.
     */
    public function dispatch(
        string $tenantId,
        string $agentId,
        string $intent,
        array $context = [],
        ?string $flowId = null
    ): array {
        // Phase 1: Inject automation_level into context for bandit baseline
        $automationLevel = $this->getTenantAutomationLevel($tenantId);
        $context['automation_level'] = $automationLevel;
        // Hard Rule #4: Pre-execution CostGovernor gate before any resource allocation
        $estimatedCost = $this->estimateExecutionCost($intent, array_merge($context, [
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'flow_id' => $flowId,
        ]));
        $costStatus = $this->costGovernor->canExecute($tenantId, $estimatedCost);
        
        if (!$costStatus['allowed']) {
            return [
                'status' => 'blocked',
                'reason' => 'budget_exceeded',
                'cost_status' => $costStatus,
                'estimated_cost' => $estimatedCost,
            ];
        }
        
        // Check agent permissions (Hard Rule: graph-based permission check)
        if (!$this->canAgentExecute($tenantId, $agentId, $intent)) {
            return [
                'status' => 'blocked',
                'reason' => 'Agent lacks permission for this intent',
            ];
        }
        
        // If degraded mode, adjust execution parameters
        if ($costStatus['degraded']) {
            $context['model_override'] = $this->costGovernor->selectModel(
                $tenantId,
                $context['preferred_model'] ?? 'gpt-4o',
                ['gpt-4o-mini', 'claude-3-haiku']
            );
            $context['degraded_mode'] = true;
        }
        
        // Create execution DAG if flow-based
        $dagId = null;
        if ($flowId) {
            $dagId = $this->createDagExecution($tenantId, $flowId, $context);
        }
        
        // Emit dispatch event (sole write target: event_log)
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent_dispatch',
            aggregateId: (string) \Illuminate\Support\Str::uuid(),
            eventType: 'agent.dispatched',
            payload: [
                'agent_id' => $agentId,
                'intent' => $intent,
                'context' => $context,
                'flow_id' => $flowId,
                'dag_id' => $dagId,
                'cost_status' => $costStatus,
            ],
            metadata: [
                'planner_version' => '3.2',
                'degraded' => $costStatus['degraded'],
                'estimated_cost' => $estimatedCost,
                'cost_estimate_source' => 'adaptive',
                // Phase 1: explicit automation_level on agent.dispatched for STE / analytics
                'automation_level' => $automationLevel,
            ]
        );
        
        // Publish to Redis for intelligence workers
        Redis::publish('agent:dispatch', json_encode([
            'event_id' => $event->id,
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'intent' => $intent,
            'context' => $context,
            'dag_id' => $dagId,
        ]));
        
        return [
            'status' => 'dispatched',
            'event_id' => $event->id,
            'dag_id' => $dagId,
            'cost_status' => $costStatus,
            'estimated_cost' => $estimatedCost,
        ];
    }
    
    /**
     * Hard Rule #3: Atlas UI and Atlas Agent NEVER share runtime memory
     * Atlas UI requests are processed through Meta-Planner with ephemeral session only.
     */
    public function processAtlasRequest(
        string $tenantId,
        string $userId,
        string $message,
        ?string $sessionId = null,
        array $context = [],
    ): array {
        $sessionId = $sessionId ?? (string) \Illuminate\Support\Str::uuid();
        
        // Record UI command intent
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'atlas_session',
            aggregateId: $sessionId,
            eventType: 'atlas.command_received',
            payload: [
                'user_id' => $userId,
                'message' => $message,
                'timestamp' => now()->toIso8601String(),
            ],
            metadata: ['source' => 'ui']
        );
        
        // Parse command into AST
        $ast = $this->parseCommandToAst($message);
        
        // Dispatch to Atlas Agent with NO shared memory
        // Merge provided context (from AtlasController) with session context
        $mergedContext = array_merge([
            'ast' => $ast,
            'original_message' => $message,
            'session_id' => $sessionId,
            'user_id' => $userId,
            // Hard Rule: UI session data is isolated, passed as context only
            'session_context' => $this->getSessionContext($sessionId),
        ], $context);  // Phase 1: override_policy and onboarding_state from AtlasController

        return $this->dispatch(
            tenantId: $tenantId,
            agentId: $this->resolveAtlasAgent($tenantId),
            intent: 'process_command',
            context: $mergedContext,
        );
    }
    
    /**
     * Graph-based agent permission check (Hard Rule implementation)
     */
    private function canAgentExecute(string $tenantId, string $agentId, string $intent): bool
    {
        $agent = DB::table('agents')
            ->where('id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();
        
        if (!$agent || $agent->status !== 'active') {
            return false;
        }
        
        $capabilities = json_decode($agent->capabilities, true) ?? [];
        
        // Map intent to required capability
        $requiredCapability = $this->mapIntentToCapability($intent);
        
        return in_array($requiredCapability, $capabilities);
    }
    
    private function mapIntentToCapability(string $intent): string
    {
        return match ($intent) {
            'process_command', 'chat' => 'chat',
            'execute_flow' => 'flow_execution',
            'generate_content' => 'content_generation',
            'analyze_data' => 'data_analysis',
            'search_memory' => 'memory_access',
            'delegate_task' => 'task_delegation',
            default => 'basic',
        };
    }

    /**
     * Estimate cost BEFORE execution allocation.
     */
    private function estimateExecutionCost(string $intent, array $context): float
    {
        $baseByIntent = [
            'process_command' => 0.02,
            'chat' => 0.02,
            'query_status' => 0.015,
            'analyze_data' => 0.04,
            'create_flow' => 0.045,
            'execute_flow' => 0.06,
            'delegate_task' => 0.03,
        ];

        $base = $baseByIntent[$intent] ?? 0.02;

        $complexity = (float) ($context['complexity'] ?? $context['ast']['complexity'] ?? 0.5);
        $complexity = max(0.0, min(1.0, $complexity));

        // Adaptive historical layer by intent / agent / flow.
        $tenantId = (string) ($context['tenant_id'] ?? '');
        $agentId = (string) ($context['agent_id'] ?? '');
        $flowId = (string) ($context['flow_id'] ?? '');

        $historical = $this->historicalUsageAverage($tenantId, $intent, $agentId, $flowId);

        $baseline = $base * (1 + (0.8 * $complexity));
        $estimated = $historical > 0 ? $historical : $baseline;

        if (!empty($context['flow_id']) || !empty($context['dag'])) {
            $estimated *= 1.2;
            $baseline *= 1.2;
        }

        $drift = $baseline > 0 ? abs($estimated - $baseline) / $baseline : 0.0;
        $maxDrift = (float) config('services.spidernet.cost_estimate_max_drift_ratio', 0.75);

        if ($drift > $maxDrift) {
            $estimated = ($baseline * 0.7) + ($estimated * 0.3);
        }

        return round(max($estimated, 0.00001), 6);
    }

    private function historicalUsageAverage(string $tenantId, string $intent, string $agentId = '', string $flowId = ''): float
    {
        if ($tenantId === '') {
            return 0.0;
        }

        $query = DB::table('usage_records')->where('tenant_id', $tenantId);

        // Intent-aware filter from metadata JSON (best-effort)
        $query->whereRaw("JSON_EXTRACT(metadata, '$.intent') = ?", [$intent]);

        if ($agentId !== '') {
            $query->where('agent_id', $agentId);
        }

        if ($flowId !== '') {
            $query->whereRaw("JSON_EXTRACT(metadata, '$.flow_id') = ?", [$flowId]);
        }

        $avg = (float) ($query->avg('cost_usd') ?? 0.0);
        return $avg;
    }
    
    private function createDagExecution(string $tenantId, string $flowId, array $context): string
    {
        $dagId = (string) \Illuminate\Support\Str::uuid();
        
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'dag_execution',
            aggregateId: $dagId,
            eventType: 'dag.created',
            payload: [
                'flow_id' => $flowId,
                'context' => $context,
                'status' => 'pending',
            ],
            metadata: []
        );
        
        return $dagId;
    }
    
    public function parseCommandToAst(string $message): array
    {
        // Simple NL parsing - production would use LLM-based NLU
        $message = strtolower(trim($message));
        
        // Pattern matching for common commands
        if (str_contains($message, 'create flow') || str_contains($message, 'new flow')) {
            return [
                'type' => 'create_flow',
                'params' => ['name' => $this->extractQuoted($message)],
            ];
        }
        
        if (str_contains($message, 'run') || str_contains($message, 'execute')) {
            return [
                'type' => 'execute',
                'params' => ['target' => $this->extractQuoted($message)],
            ];
        }
        
        if (str_contains($message, 'status') || str_contains($message, 'health')) {
            return [
                'type' => 'query_status',
                'params' => [],
            ];
        }
        
        return [
            'type' => 'chat',
            'params' => ['message' => $message],
        ];
    }
    
    private function extractQuoted(string $message): ?string
    {
        if (preg_match('/"([^"]+)"/', $message, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    private function resolveAtlasAgent(string $tenantId): string
    {
        // Atlas is the default NL compiler agent
        $atlas = DB::table('agents')
            ->where('tenant_id', $tenantId)
            ->where('slug', 'atlas')
            ->first();
        
        return $atlas?->id ?? throw new \RuntimeException('Atlas agent not found');
    }
    
    private function getSessionContext(string $sessionId): array
    {
        // Get recent session history from event_log only
        $events = $this->eventStore->getEvents('atlas_session', $sessionId);
        
        return $events
            ->where('event_type', 'atlas.command_received')
            ->take(5)
            ->map(fn($e) => $e->payload['message'] ?? '')
            ->values()
            ->toArray();
    }

    /**
     * Phase 1: Get tenant's automation level for bandit baseline
     * Used to inject into every agent dispatch for causal inference
     */
    private function getTenantAutomationLevel(string $tenantId): string
    {
        // Use cache to avoid repeated queries
        return cache()->remember(
            "tenant:{$tenantId}:automation_level",
            60, // 1 minute TTL - short to allow settings changes to propagate
            function () use ($tenantId) {
                $tenant = Tenant::find($tenantId);
                return $tenant?->automation_level ?? 'assisted';
            }
        );
    }
}
