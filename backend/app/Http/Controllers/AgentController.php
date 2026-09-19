<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\EventStore;
use App\Services\MetaPlanner;
use App\Services\ReplayDivergenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class AgentController extends Controller
{
    private EventStore $eventStore;

    private MetaPlanner $metaPlanner;

    private ReplayDivergenceService $replayDivergence;

    public function __construct(EventStore $eventStore, MetaPlanner $metaPlanner, ReplayDivergenceService $replayDivergence)
    {
        $this->eventStore = $eventStore;
        $this->metaPlanner = $metaPlanner;
        $this->replayDivergence = $replayDivergence;
    }

    /**
     * List agents scoped to tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $agents = DB::table('agents')
            ->where('tenant_id', $tenantId)

            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($agents);
    }

    /**
     * Register a new agent via EventStore (Hard Rule #1).
     * Supports type='dynamic' with JSONB config (system_prompt, model, tools, delegation).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash',
            'type' => 'required|string|in:static,dynamic',
            'description' => 'nullable|string|max:2000',
            'capabilities' => 'nullable|array',
            'capabilities.*' => 'string',
            'config' => 'nullable|array',
            'config.system_prompt' => 'required_if:type,dynamic|nullable|string',
            'config.model' => 'nullable|string|max:100',
            'config.tools' => 'nullable|array',
            'config.tools.*' => 'string',
            'config.delegation' => 'nullable|array',
            'config.delegation.*' => 'string',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $agentId = (string) Str::uuid();

        // Check slug uniqueness within tenant
        $slugExists = DB::table('agents')
            ->where('tenant_id', $tenantId)
            ->where('slug', $request->input('slug'))

            ->exists();

        if ($slugExists) {
            return response()->json([
                'error' => 'An agent with this slug already exists.',
            ], 422);
        }

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent',
            aggregateId: $agentId,
            eventType: 'agent.registered',
            payload: [
                'name' => $request->input('name'),
                'slug' => $request->input('slug'),
                'type' => $request->input('type'),
                'description' => $request->input('description'),
                'capabilities' => $request->input('capabilities', []),
                'config' => $request->input('config', []),
                'status' => 'inactive',
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Write to agents projection
        DB::table('agents')->insert([
            'id' => $agentId,
            'tenant_id' => $tenantId,
            'name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'type' => $request->input('type'),
            'description' => $request->input('description'),
            'capabilities' => json_encode($request->input('capabilities', [])),
            'config' => json_encode($request->input('config', [])),
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // If delegation config is provided, create delegation edges
        $delegations = $request->input('config.delegation', []);
        foreach ($delegations as $targetSlug) {
            $target = DB::table('agents')
                ->where('tenant_id', $tenantId)
                ->where('slug', $targetSlug)

                ->first();

            if ($target) {
                DB::table('agent_delegations')->insert([
                    'agent_id' => $agentId,
                    'delegate_id' => $target->id,
                    'permission' => 'delegate',
                    'conditions' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json([
            'id' => $agentId,
            'event_id' => $event->id,
            'status' => 'inactive',
            'message' => 'Agent registered successfully.',
        ], 201);
    }

    /**
     * Get a single agent with capabilities and delegation edges.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $agent = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (! $agent) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        $agent->capabilities = json_decode($agent->capabilities, true);
        $agent->config = json_decode($agent->config, true);

        $delegations = $this->tenantDelegations($tenantId, $id);

        // `data` envelope: the cockpit store reads response.data.data, and the
        // embedded delegations sit alongside the agent fields.
        $payload = (array) $agent;
        $payload['delegations'] = $delegations;

        return response()->json(['data' => $payload]);
    }

    /**
     * GET /agents/{agent}/delegations — this agent's delegate edges,
     * tenant-scoped (consumed by the cockpit's fetchDelegations).
     */
    public function delegations(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $exists = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        return response()->json(['data' => $this->tenantDelegations($tenantId, $id)]);
    }

    /**
     * Delegate edges for an agent, EXCLUDING delegates that belong to another
     * tenant — cross-tenant rows must never leak agent names/slugs.
     */
    private function tenantDelegations(string $tenantId, string $agentId): Collection
    {
        return DB::table('agent_delegations')
            ->join('agents', 'agent_delegations.delegate_id', '=', 'agents.id')
            ->where('agent_delegations.agent_id', $agentId)
            ->where('agents.tenant_id', $tenantId)
            ->select(
                'agent_delegations.id as delegation_id',
                'agents.id as agent_id',
                'agents.name',
                'agents.slug',
                'agents.type',
                'agents.status',
                'agent_delegations.permission'
            )
            ->get();
    }

    /**
     * Update an agent via EventStore (Hard Rule #1).
     */
    public function update(Request $request, $id): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|alpha_dash',
            'description' => 'nullable|string|max:2000',
            'capabilities' => 'nullable|array',
            'capabilities.*' => 'string',
            'config' => 'nullable|array',
            'config.system_prompt' => 'nullable|string',
            'config.model' => 'nullable|string|max:100',
            'config.tools' => 'nullable|array',
            'config.delegation' => 'nullable|array',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $agent = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (! $agent) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        // Check slug uniqueness if slug is changing
        if ($request->has('slug') && $request->input('slug') !== $agent->slug) {
            $slugExists = DB::table('agents')
                ->where('tenant_id', $tenantId)
                ->where('slug', $request->input('slug'))
                ->where('id', '!=', $id)

                ->exists();

            if ($slugExists) {
                return response()->json([
                    'error' => 'An agent with this slug already exists.',
                ], 422);
            }
        }

        $changes = $request->only(['name', 'slug', 'description', 'capabilities', 'config']);

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent',
            aggregateId: $id,
            eventType: 'agent.updated',
            payload: [
                'changes' => $changes,
                'previous' => [
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                    'description' => $agent->description,
                    'capabilities' => json_decode($agent->capabilities, true),
                    'config' => json_decode($agent->config, true),
                ],
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Update projection
        $updateData = ['updated_at' => now()];
        if (isset($changes['name'])) {
            $updateData['name'] = $changes['name'];
        }
        if (isset($changes['slug'])) {
            $updateData['slug'] = $changes['slug'];
        }
        if (array_key_exists('description', $changes)) {
            $updateData['description'] = $changes['description'];
        }
        if (isset($changes['capabilities'])) {
            $updateData['capabilities'] = json_encode($changes['capabilities']);
        }
        if (isset($changes['config'])) {
            $updateData['config'] = json_encode($changes['config']);
        }

        DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update($updateData);

        // Invalidate cache on critical model/tool/delegation/system prompt updates
        if (isset($changes['config'])) {
            $critical = ['model', 'tools', 'delegation', 'system_prompt'];
            $configChanged = array_intersect($critical, array_keys($changes['config'] ?? []));
            if (! empty($configChanged)) {
                $this->replayDivergence->invalidateByCriticalAgentConfig($tenantId, (string) $id);
            }
        }

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'message' => 'Agent updated successfully.',
        ]);
    }

    /**
     * Delete an agent via EventStore (Hard Rule #1).
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $agent = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (! $agent) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent',
            aggregateId: $id,
            eventType: 'agent.deleted',
            payload: [
                'name' => $agent->name,
                'slug' => $agent->slug,
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Projection removal handled as delete (no deleted_at column in schema)
        DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->delete();

        // Remove delegation edges
        DB::table('agent_delegations')
            ->where(function ($q) use ($id) {
                $q->where('agent_id', $id)
                    ->orWhere('delegate_id', $id);
            })
            ->delete();

        $this->replayDivergence->invalidateByCriticalAgentConfig($tenantId, (string) $id);

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'message' => 'Agent deleted successfully.',
        ]);
    }

    /**
     * Toggle agent active/inactive status via EventStore.
     */
    public function toggleStatus(Request $request, $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|string|in:active,inactive',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $agent = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (! $agent) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        $newStatus = $request->input('status');

        if ($agent->status === $newStatus) {
            return response()->json([
                'error' => "Agent is already {$newStatus}.",
            ], 409);
        }

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent',
            aggregateId: $id,
            eventType: 'agent.status_toggled',
            payload: [
                'previous_status' => $agent->status,
                'new_status' => $newStatus,
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Update projection
        DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'status' => $newStatus,
            'message' => "Agent status set to {$newStatus}.",
        ]);
    }

    /**
     * Dispatch agent via MetaPlanner (Hard Rule #2).
     */
    public function dispatch(Request $request, $id): JsonResponse
    {
        $request->validate([
            'intent' => 'required|string|max:255',
            'context' => 'nullable|array',
            'flow_id' => 'nullable|string|uuid',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        // Verify agent belongs to tenant and is active
        $agent = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (! $agent) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        if ($agent->status !== 'active') {
            return response()->json(['error' => 'Agent is not active.'], 422);
        }

        // Hard Rule #2: MetaPlanner is the ONLY decision authority
        $result = $this->metaPlanner->dispatch(
            tenantId: $tenantId,
            agentId: $id,
            intent: $request->input('intent'),
            context: $request->input('context', []),
            flowId: $request->input('flow_id'),
        );

        return response()->json($result);
    }

    /**
     * Test dispatch for preview — same as dispatch but with test flag.
     */
    public function test(Request $request, $id): JsonResponse
    {
        $request->validate([
            'intent' => 'required|string|max:255',
            'context' => 'nullable|array',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $agent = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (! $agent) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        // Dispatch through MetaPlanner with test flag
        $result = $this->metaPlanner->dispatch(
            tenantId: $tenantId,
            agentId: $id,
            intent: $request->input('intent'),
            context: array_merge($request->input('context', []), [
                'test_mode' => true,
                'dry_run' => true,
            ]),
        );

        return response()->json([
            'test' => true,
            'result' => $result,
        ]);
    }

    /**
     * Activate agent and publish Redis event for Python hot-load.
     */
    public function activate(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $agent = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (! $agent) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent',
            aggregateId: $id,
            eventType: 'agent.activated',
            payload: [
                'previous_status' => $agent->status,
                'new_status' => 'active',
                'config' => json_decode($agent->config, true),
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Update projection
        DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);

        // Publish agent:registered Redis event for Python hot-load
        Redis::publish('agent:registered', json_encode([
            'event_id' => $event->id,
            'tenant_id' => $tenantId,
            'agent_id' => $id,
            'slug' => $agent->slug,
            'type' => $agent->type,
            'config' => json_decode($agent->config, true),
            'capabilities' => json_decode($agent->capabilities, true),
        ]));

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'status' => 'active',
            'message' => 'Agent activated and registered for hot-load.',
        ]);
    }

    /**
     * Query event_log for agent sessions.
     */
    public function sessions(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        // Verify agent belongs to tenant
        $agentExists = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->exists();

        if (! $agentExists) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        // Query event_log for dispatch events related to this agent
        $sessions = DB::table('event_log')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', 'agent_dispatch')
            ->whereRaw("JSON_EXTRACT(payload, '$.agent_id') = ?", [$id])
            ->orderBy('occurred_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($sessions);
    }

    /**
     * Get agent capabilities.
     */
    public function capabilities(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $agent = DB::table('agents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (! $agent) {
            return response()->json(['error' => 'Agent not found.'], 404);
        }

        $capabilities = json_decode($agent->capabilities, true) ?? [];
        $config = json_decode($agent->config, true) ?? [];

        return response()->json([
            'agent_id' => $id,
            'capabilities' => $capabilities,
            'tools' => $config['tools'] ?? [],
            'model' => $config['model'] ?? null,
            'delegation' => $config['delegation'] ?? [],
        ]);
    }

    /**
     * Build delegation graph from agent_delegations table.
     */
    public function delegationGraph(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        // Fetch all active agents as nodes
        $agents = DB::table('agents')
            ->where('tenant_id', $tenantId)

            ->select('id', 'name', 'slug', 'type', 'status')
            ->get();

        $nodes = $agents->map(function ($agent) {
            return [
                'id' => $agent->id,
                'label' => $agent->name,
                'slug' => $agent->slug,
                'type' => $agent->type,
                'status' => $agent->status,
            ];
        })->values()->toArray();

        // Fetch delegation edges where BOTH endpoints are this tenant's agents
        // — a cross-tenant delegate edge must not appear in the graph.
        $tenantAgentIds = $agents->pluck('id');
        $delegations = DB::table('agent_delegations')
            ->whereIn('agent_id', $tenantAgentIds)
            ->whereIn('delegate_id', $tenantAgentIds)
            ->select('id', 'agent_id', 'delegate_id', 'permission')
            ->get();

        $edges = $delegations->map(function ($d) {
            return [
                'id' => $d->id,
                'source' => $d->agent_id,
                'target' => $d->delegate_id,
                'permission' => $d->permission,
            ];
        })->values()->toArray();

        return response()->json([
            'graph' => [
                'nodes' => $nodes,
                'edges' => $edges,
            ],
        ]);
    }

    /**
     * Return predefined agent templates.
     */
    public function templates(): JsonResponse
    {
        $templates = [
            [
                'slug' => 'executive-assistant',
                'name' => 'Executive Assistant',
                'description' => 'Manages schedules, emails, and task prioritization. Delegates sub-tasks to specialist agents.',
                'type' => 'dynamic',
                'capabilities' => ['chat', 'task_delegation', 'content_generation'],
                'config' => [
                    'system_prompt' => 'You are an executive assistant AI. You help manage schedules, draft communications, prioritize tasks, and delegate work to specialist agents when appropriate.',
                    'model' => 'gpt-4o',
                    'tools' => ['calendar', 'email', 'task_manager'],
                    'delegation' => ['analyst', 'monitor'],
                ],
            ],
            [
                'slug' => 'analyst',
                'name' => 'Analyst',
                'description' => 'Performs data analysis, generates reports, and provides insights from structured and unstructured data.',
                'type' => 'dynamic',
                'capabilities' => ['data_analysis', 'content_generation', 'memory_access'],
                'config' => [
                    'system_prompt' => 'You are a data analyst AI. You analyze data, generate reports, identify trends, and provide actionable insights. You work with both structured and unstructured data sources.',
                    'model' => 'gpt-4o',
                    'tools' => ['sql_query', 'chart_generator', 'data_transform'],
                    'delegation' => [],
                ],
            ],
            [
                'slug' => 'monitor',
                'name' => 'Monitor',
                'description' => 'Watches system health, resource usage, and alerts. Triggers automated responses to anomalies.',
                'type' => 'dynamic',
                'capabilities' => ['monitoring', 'basic'],
                'config' => [
                    'system_prompt' => 'You are a system monitor AI. You watch system health metrics, resource usage, and alerting thresholds. You trigger automated responses when anomalies are detected.',
                    'model' => 'gpt-4o-mini',
                    'tools' => ['metrics_reader', 'alert_manager', 'health_check'],
                    'delegation' => [],
                ],
            ],
            [
                'slug' => 'builder',
                'name' => 'Builder',
                'description' => 'Generates code, configurations, and infrastructure templates. Specializes in flow DAG construction.',
                'type' => 'dynamic',
                'capabilities' => ['content_generation', 'flow_execution'],
                'config' => [
                    'system_prompt' => 'You are a builder AI. You generate code, write configurations, construct flow DAGs, and create infrastructure templates. You focus on producing correct, maintainable output.',
                    'model' => 'gpt-4o',
                    'tools' => ['code_generator', 'dag_builder', 'template_engine'],
                    'delegation' => ['analyst'],
                ],
            ],
            [
                'slug' => 'tutor',
                'name' => 'Tutor',
                'description' => 'Provides interactive learning experiences, explains concepts, and guides users through complex topics.',
                'type' => 'dynamic',
                'capabilities' => ['chat', 'content_generation', 'memory_access'],
                'config' => [
                    'system_prompt' => 'You are a tutor AI. You provide clear, interactive learning experiences. You explain complex concepts step by step, use analogies, and adapt your teaching style to the learner.',
                    'model' => 'gpt-4o',
                    'tools' => ['knowledge_base', 'quiz_generator', 'progress_tracker'],
                    'delegation' => ['analyst'],
                ],
            ],
        ];

        return response()->json(['templates' => $templates]);
    }
}
