<?php

namespace App\Http\Controllers;

use App\Services\EventStore;
use App\Services\ReplayDivergenceService;
use App\Services\DagExecutionService;
use App\Services\FlowTemplateBuilder;
use App\Models\Flow;
use App\Models\FlowExecution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowController extends Controller
{
    private EventStore $eventStore;
    private ReplayDivergenceService $replayDivergence;

    public function __construct(EventStore $eventStore, ReplayDivergenceService $replayDivergence)
    {
        $this->eventStore = $eventStore;
        $this->replayDivergence = $replayDivergence;
    }

    /**
     * List flows scoped to tenant with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $perPage = $request->input('per_page', 20);

        $flows = DB::table('flows')
            ->where('tenant_id', $tenantId)

            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($flows);
    }

    /**
     * Create a new flow via EventStore (Hard Rule #1).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash',
            'description' => 'nullable|string|max:2000',
            'dag' => 'required|array',
            'dag.nodes' => 'required|array|min:1',
            'dag.edges' => 'nullable|array',
            'triggers' => 'nullable|array',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $flowId = (string) Str::uuid();

        // Ensure slug is unique within tenant
        $slugExists = DB::table('flows')
            ->where('tenant_id', $tenantId)
            ->where('slug', $request->input('slug'))

            ->exists();

        if ($slugExists) {
            return response()->json([
                'error' => 'A flow with this slug already exists.',
            ], 422);
        }

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'flow',
            aggregateId: $flowId,
            eventType: 'flow.created',
            payload: [
                'name' => $request->input('name'),
                'slug' => $request->input('slug'),
                'description' => $request->input('description'),
                'dag' => $request->input('dag'),
                'triggers' => $request->input('triggers', []),
                'status' => 'draft',
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Write to flows projection
        DB::table('flows')->insert([
            'id' => $flowId,
            'tenant_id' => $tenantId,
            'name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'description' => $request->input('description'),
            'dag' => json_encode($request->input('dag')),
            'triggers' => json_encode($request->input('triggers', [])),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $flowId,
            'event_id' => $event->id,
            'status' => 'draft',
            'message' => 'Flow created successfully.',
        ], 201);
    }

    /**
     * Quick-create a published flow from a first-win template.
     */
    public function quickCreate(Request $request, FlowTemplateBuilder $builder): JsonResponse
    {
        $validated = $request->validate([
            'template' => 'required|string|in:status,followup,invoice',
            'who' => 'nullable|string|max:255',
            'when' => 'nullable|string|max:255',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $who = (string) ($validated['who'] ?? 'team');
        $when = (string) ($validated['when'] ?? 'Every weekday morning');
        $built = $builder->build($validated['template'], $who, $when);
        $flowId = (string) Str::uuid();

        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'flow',
            aggregateId: $flowId,
            eventType: 'flow.created',
            payload: [
                'name' => $built['name'],
                'slug' => $built['slug'],
                'description' => $built['description'],
                'dag' => $built['dag'],
                'triggers' => $built['triggers'],
                'status' => 'published',
                'source' => 'quick_create',
            ],
            metadata: ['user_id' => $request->user()?->id],
        );

        // FlowProjection inserts the row on flow.created; enrich DAG + schedule before publish.
        DB::table('flows')->where('id', $flowId)->update([
            'dag' => json_encode($built['dag']),
            'description' => $built['description'],
            'schedule_cron' => $built['schedule_cron'],
            'schedule_timezone' => 'UTC',
            'updated_at' => now(),
        ]);

        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'flow',
            aggregateId: $flowId,
            eventType: 'flow.published',
            payload: ['name' => $built['name'], 'slug' => $built['slug']],
            metadata: ['user_id' => $request->user()?->id],
        );

        return response()->json([
            'data' => [
                'id' => $flowId,
                'name' => $built['name'],
                'slug' => $built['slug'],
                'status' => 'published',
                'dag' => $built['dag'],
            ],
        ], 201);
    }

    /**
     * Get a single flow with tenant scope.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $flow = DB::table('flows')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (!$flow) {
            return response()->json(['error' => 'Flow not found.'], 404);
        }

        // Decode JSON fields for response
        $flow->dag = json_decode($flow->dag, true);
        $flow->triggers = json_decode($flow->triggers, true);

        return response()->json(['flow' => $flow]);
    }

    /**
     * Update a flow via EventStore (Hard Rule #1).
     */
    public function update(Request $request, $id): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|alpha_dash',
            'description' => 'nullable|string|max:2000',
            'dag' => 'sometimes|array',
            'dag.nodes' => 'required_with:dag|array|min:1',
            'dag.edges' => 'nullable|array',
            'triggers' => 'nullable|array',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $flow = DB::table('flows')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (!$flow) {
            return response()->json(['error' => 'Flow not found.'], 404);
        }

        // Check slug uniqueness if slug is being changed
        if ($request->has('slug') && $request->input('slug') !== $flow->slug) {
            $slugExists = DB::table('flows')
                ->where('tenant_id', $tenantId)
                ->where('slug', $request->input('slug'))
                ->where('id', '!=', $id)
    
                ->exists();

            if ($slugExists) {
                return response()->json([
                    'error' => 'A flow with this slug already exists.',
                ], 422);
            }
        }

        $changes = $request->only(['name', 'slug', 'description', 'dag', 'triggers']);

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'flow',
            aggregateId: $id,
            eventType: 'flow.updated',
            payload: [
                'changes' => $changes,
                'previous' => [
                    'name' => $flow->name,
                    'slug' => $flow->slug,
                    'description' => $flow->description,
                    'dag' => json_decode($flow->dag, true),
                    'triggers' => json_decode($flow->triggers, true),
                ],
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Update projection
        $updateData = ['updated_at' => now()];
        if (isset($changes['name'])) $updateData['name'] = $changes['name'];
        if (isset($changes['slug'])) $updateData['slug'] = $changes['slug'];
        if (array_key_exists('description', $changes)) $updateData['description'] = $changes['description'];
        if (isset($changes['dag'])) $updateData['dag'] = json_encode($changes['dag']);
        if (isset($changes['triggers'])) $updateData['triggers'] = json_encode($changes['triggers']);

        DB::table('flows')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update($updateData);

        // Invalidate fingerprint cache when DAG or critical flow fields changed
        if (isset($changes['dag']) || isset($changes['triggers']) || isset($changes['slug'])) {
            $this->replayDivergence->invalidateByFlow($tenantId, (string) $id);
        }

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'message' => 'Flow updated successfully.',
        ]);
    }

    /**
     * Soft-delete a flow via EventStore (Hard Rule #1).
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $flow = DB::table('flows')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (!$flow) {
            return response()->json(['error' => 'Flow not found.'], 404);
        }

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'flow',
            aggregateId: $id,
            eventType: 'flow.deleted',
            payload: [
                'name' => $flow->name,
                'slug' => $flow->slug,
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Projection removal handled as delete (no deleted_at column in schema)
        DB::table('flows')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->delete();

        $this->replayDivergence->invalidateByFlow($tenantId, (string) $id);

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'message' => 'Flow deleted successfully.',
        ]);
    }

    /**
     * Execute a flow via DagExecutionService (real node execution).
     */
    public function execute(Request $request, $id, DagExecutionService $dagExecution): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $flow = DB::table('flows')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $flow) {
            return response()->json(['error' => 'Flow not found.'], 404);
        }

        if ($flow->status !== 'published') {
            return response()->json(['error' => 'Flow must be published before execution.'], 422);
        }

        $triggers = json_decode($flow->triggers ?? '[]', true);
        if (! is_array($triggers)) {
            $triggers = [];
        }

        $context = array_merge(
            (array) ($triggers['context'] ?? []),
            $request->input('context', []),
        );

        $result = $dagExecution->createExecution($tenantId, (string) $id, $context);

        return response()->json([
            'execution_id' => $result['execution_id'] ?? null,
            'flow_id' => $id,
            'status' => $result['status'] ?? 'running',
            'message' => 'Flow execution started.',
        ], 202);
    }

    /**
     * Publish a flow — set status to 'published' via EventStore.
     */
    public function publish(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $flow = DB::table('flows')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->first();

        if (!$flow) {
            return response()->json(['error' => 'Flow not found.'], 404);
        }

        if ($flow->status === 'published') {
            return response()->json(['error' => 'Flow is already published.'], 409);
        }

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'flow',
            aggregateId: $id,
            eventType: 'flow.published',
            payload: [
                'previous_status' => $flow->status,
                'new_status' => 'published',
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Update projection
        DB::table('flows')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'status' => 'published',
            'message' => 'Flow published successfully.',
        ]);
    }

    /**
     * List executions for a flow.
     */
    public function executions(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        // Verify flow belongs to tenant
        $flowExists = DB::table('flows')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)

            ->exists();

        if (!$flowExists) {
            return response()->json(['error' => 'Flow not found.'], 404);
        }

        $executions = DB::table('flow_executions')
            ->where('flow_id', $id)
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($executions);
    }

    /**
     * Get status of a single flow execution.
     */
    public function executionStatus(Request $request, $executionId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $execution = DB::table('flow_executions')
            ->where('id', $executionId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$execution) {
            return response()->json(['error' => 'Execution not found.'], 404);
        }

        // Decode JSON fields
        $execution->context = json_decode($execution->context, true);

        // Fetch related events for detailed status
        $events = DB::table('event_log')
            ->where('aggregate_type', 'flow_execution')
            ->where('aggregate_id', $executionId)
            ->where('tenant_id', $tenantId)
            ->orderBy('version')
            ->get()
            ->map(function ($e) {
                $e->payload = json_decode($e->payload, true);
                return $e;
            });

        return response()->json([
            'execution' => $execution,
            'events' => $events,
        ]);
    }
}
