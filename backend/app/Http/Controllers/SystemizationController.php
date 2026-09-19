<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BusinessProcess;
use App\Models\BusinessSystem;
use App\Models\Sop;
use App\Services\ApprovalEngine;
use App\Services\DagExecutionService;
use App\Services\EventStore;
use App\Services\Systemization\ProcessRunRecorder;
use App\Services\Systemization\SopFlowCompiler;
use App\Services\Systemization\SopInterviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Business Systemization pack — systems map, one-owner-per-process,
 * snowball delegation queue, and interviewed SOPs.
 */
class SystemizationController extends Controller
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly SopInterviewService $interview,
    ) {
    }

    /**
     * POST /api/systemization/bootstrap — seed the six core functions.
     *
     * Pass seed_templates=true to also install the recruitment + retraining
     * playbooks from the template library (systems:seed-templates); only
     * fires when template files actually exist.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $validated = $request->validate([
            'seed_templates' => 'sometimes|boolean',
        ]);

        $defaults = [
            'marketing' => 'Generate qualified leads',
            'sales' => 'Turn leads into customers',
            'operations' => 'Deliver the product or service',
            'finance' => 'Manage cash flow and profitability',
            'recruitment' => 'Attract and hire the right people',
            'management' => 'Keep every system owned, measured, and improving',
        ];

        $created = [];

        foreach ($defaults as $function => $goal) {
            $system = BusinessSystem::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'function' => $function, 'name' => ucfirst($function)],
                ['goal' => $goal, 'status' => 'active'],
            );

            if ($system->wasRecentlyCreated) {
                $created[] = $function;
                $this->eventStore->append(
                    $tenantId,
                    'systemization',
                    (string) $system->id,
                    'systemization.system.created',
                    ['function' => $function, 'name' => $system->name, 'source' => 'bootstrap'],
                );
            }
        }

        $templatesSeeded = false;

        if (($validated['seed_templates'] ?? false)
            && \App\Console\Commands\SeedSystemTemplates::templatesAvailable()) {
            \Illuminate\Support\Facades\Artisan::call('systems:seed-templates', [
                '--tenant' => $tenantId,
            ]);
            $templatesSeeded = true;
        }

        return response()->json(['data' => ['created' => $created, 'templates_seeded' => $templatesSeeded]]);
    }

    /**
     * GET /api/systemization/map — the full systems map plus founder load.
     * Each system also lists the catalogue skills mapped onto its function
     * (`skills[]`: slug, name, pillar, node_id, enabled, stage) for the business map.
     */
    public function map(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $systems = BusinessSystem::query()
            ->where('tenant_id', $tenantId)
            ->with(['processes' => fn ($q) => $q
                ->orderBy('position')
                ->orderBy('effort_size')
                ->withExists(['sops as has_published_sop' => fn ($s) => $s->where('status', 'published')])])
            ->orderBy('function')
            ->get();

        // Additive: a catalogue problem must never take the systems map down with it.
        try {
            $skillsByFunction = app(\App\Services\Map\BusinessMapService::class)->skillsByFunction($tenantId);
        } catch (\Throwable $e) {
            report($e);
            $skillsByFunction = [];
        }
        $systems->each(fn (BusinessSystem $system) => $system->setAttribute('skills', $skillsByFunction[$system->function] ?? []));

        $allProcesses = $systems->flatMap->processes;
        $founderOwned = $allProcesses->where('owner_type', 'founder');

        return response()->json([
            'data' => [
                'functions' => BusinessSystem::FUNCTIONS,
                'systems' => $systems,
                'founder_load' => [
                    'total_processes' => $allProcesses->count(),
                    'founder_owned' => $founderOwned->count(),
                    'delegated' => $allProcesses->where('owner_type', 'team')->count(),
                    'automated' => $allProcesses->where('owner_type', 'agent')->count(),
                    'founder_owned_pct' => $allProcesses->count() > 0
                        ? (int) round($founderOwned->count() / $allProcesses->count() * 100)
                        : 0,
                ],
            ],
        ]);
    }

    /**
     * POST /api/systemization/systems
     */
    public function storeSystem(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $validated = $request->validate([
            'function' => ['required', Rule::in(BusinessSystem::FUNCTIONS)],
            'name' => 'required|string|max:120',
            'goal' => 'nullable|string|max:500',
        ]);

        $system = BusinessSystem::create([
            'tenant_id' => $tenantId,
            ...$validated,
        ]);

        $this->eventStore->append(
            $tenantId,
            'systemization',
            (string) $system->id,
            'systemization.system.created',
            ['function' => $system->function, 'name' => $system->name],
        );

        return response()->json(['data' => $system], 201);
    }

    /**
     * POST /api/systemization/systems/{system}/processes
     */
    public function storeProcess(Request $request, string $systemId): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $system = BusinessSystem::query()
            ->where('tenant_id', $tenantId)
            ->find($systemId);

        if (! $system) {
            return response()->json(['message' => 'System not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:160',
            'goal' => 'nullable|string|max:500',
            'effort_size' => 'nullable|integer|min:1|max:5',
        ]);

        $process = BusinessProcess::create([
            'tenant_id' => $tenantId,
            'system_id' => (string) $system->id,
            'name' => $validated['name'],
            'goal' => $validated['goal'] ?? null,
            'effort_size' => $validated['effort_size'] ?? 3,
            'owner_type' => 'founder',
            'status' => 'founder_owned',
        ]);

        return response()->json(['data' => $process], 201);
    }

    /**
     * PATCH /api/systemization/processes/{process} — assign ownership etc.
     * One owner per process: setting an owner clears the other owner kind.
     */
    public function updateProcess(Request $request, string $processId): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $process = BusinessProcess::query()
            ->where('tenant_id', $tenantId)
            ->find($processId);

        if (! $process) {
            return response()->json(['message' => 'Process not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:160',
            'goal' => 'sometimes|nullable|string|max:500',
            'effort_size' => 'sometimes|integer|min:1|max:5',
            'position' => 'sometimes|integer|min:0',
            'owner_type' => ['sometimes', Rule::in(['founder', 'team', 'agent'])],
            'owner_user_id' => 'sometimes|nullable|uuid',
            'owner_agent_id' => 'sometimes|nullable|uuid',
        ]);

        $ownershipChanged = false;

        if (array_key_exists('owner_type', $validated)) {
            $ownershipChanged = true;
            $process->owner_type = $validated['owner_type'];

            // Exactly one owner. Everybody/somebody/anybody/nobody is how
            // tasks die — the model makes ambiguity unrepresentable.
            $process->owner_user_id = $validated['owner_type'] === 'team'
                ? ($validated['owner_user_id'] ?? null)
                : null;
            $process->owner_agent_id = $validated['owner_type'] === 'agent'
                ? ($validated['owner_agent_id'] ?? null)
                : null;

            $process->status = match ($validated['owner_type']) {
                'team' => 'delegated',
                'agent' => 'automated',
                default => 'founder_owned',
            };
        }

        foreach (['name', 'goal', 'effort_size', 'position'] as $field) {
            if (array_key_exists($field, $validated)) {
                $process->{$field} = $validated[$field];
            }
        }

        $process->save();

        if ($ownershipChanged) {
            $this->eventStore->append(
                $tenantId,
                'systemization',
                (string) $process->id,
                'systemization.process.owner_assigned',
                [
                    'process' => $process->name,
                    'owner_type' => $process->owner_type,
                    'owner_user_id' => $process->owner_user_id,
                    'owner_agent_id' => $process->owner_agent_id,
                ],
            );
        }

        return response()->json(['data' => $process->fresh()]);
    }

    /**
     * GET /api/systemization/snowball — founder-owned processes smallest
     * first: systematize + delegate in this order to compound freed time.
     */
    public function snowball(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $queue = BusinessProcess::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', 'founder')
            ->with('system:id,function,name')
            ->orderBy('effort_size')
            ->orderBy('created_at')
            ->get()
            ->map(fn (BusinessProcess $p) => [
                'id' => (string) $p->id,
                'name' => $p->name,
                'goal' => $p->goal,
                'effort_size' => $p->effort_size,
                'function' => $p->system?->function,
                'system' => $p->system?->name,
                'has_published_sop' => $p->sops()->where('status', 'published')->exists(),
            ]);

        $next = $queue->first();

        return response()->json([
            'data' => [
                'queue' => $queue,
                'next_action' => $next
                    ? "Systematize \"{$next['name']}\" next — it's your smallest owned process. Write its SOP, then hand it to a teammate or agent."
                    : 'Nothing on your plate. Every process has an owner that isn\'t you — the business runs without you.',
            ],
        ]);
    }

    /**
     * POST /api/systemization/processes/{process}/sops
     *
     * Runs the clarification interview. Incomplete answers return 422 with
     * the follow-up questions; complete answers create the next draft version.
     */
    public function storeSop(Request $request, string $processId): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $process = BusinessProcess::query()
            ->where('tenant_id', $tenantId)
            ->find($processId);

        if (! $process) {
            return response()->json(['message' => 'Process not found.'], 404);
        }

        $answers = $request->validate([
            'title' => 'required|string|max:160',
            'purpose' => 'nullable|string|max:2000',
            'trigger' => 'nullable|string|max:500',
            'tools' => 'nullable|array',
            'tools.*' => 'string|max:120',
            'steps' => 'nullable|array',
            'steps.*' => 'string|max:2000',
            'quality_criteria' => 'nullable|array',
            'quality_criteria.*' => 'string|max:500',
        ]);

        $review = $this->interview->evaluate($answers);

        if (! $review['complete']) {
            return response()->json([
                'message' => 'The SOP is not followable yet — answer these first.',
                'questions' => $review['questions'],
            ], 422);
        }

        $version = (int) Sop::query()->where('process_id', (string) $process->id)->max('version') + 1;

        $sop = Sop::create([
            'tenant_id' => $tenantId,
            'process_id' => (string) $process->id,
            'version' => $version,
            'title' => $answers['title'],
            'purpose' => $answers['purpose'] ?? null,
            'trigger' => $answers['trigger'] ?? null,
            'tools' => $answers['tools'] ?? [],
            'steps' => $answers['steps'] ?? [],
            'quality_criteria' => $answers['quality_criteria'] ?? [],
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $sop], 201);
    }

    /**
     * POST /api/systemization/processes/{process}/automate
     *
     * Compiles the published SOP into an executable Flow (the runbook) and
     * optionally puts it on a schedule. From here the process runs through
     * the same dispatch path as every other flow — MetaPlanner, CostGovernor,
     * approval gates included.
     */
    public function automate(Request $request, string $processId, SopFlowCompiler $compiler): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $process = BusinessProcess::query()->where('tenant_id', $tenantId)->find($processId);

        if (! $process) {
            return response()->json(['message' => 'Process not found.'], 404);
        }

        $validated = $request->validate([
            'schedule' => ['nullable', Rule::in(SopFlowCompiler::SCHEDULES)],
        ]);

        $sop = Sop::query()
            ->where('process_id', (string) $process->id)
            ->where('status', 'published')
            ->orderByDesc('version')
            ->first();

        if (! $sop) {
            return response()->json([
                'message' => 'Publish an SOP first — the runbook is compiled from it. An agent can only own what is documented.',
            ], 422);
        }

        $schedule = $validated['schedule'] ?? 'manual';
        $flow = $compiler->compile($process, $sop, $schedule);

        $process->forceFill([
            'flow_id' => (string) $flow->id,
            'schedule_cron' => $schedule === 'manual' ? null : $schedule,
        ])->save();

        $this->eventStore->append(
            $tenantId,
            'systemization',
            (string) $process->id,
            'systemization.process.automated',
            [
                'process' => $process->name,
                'flow_id' => (string) $flow->id,
                'sop_version' => $sop->version,
                'schedule' => $schedule,
                'owner_agent_id' => $process->owner_agent_id,
            ],
        );

        return response()->json([
            'data' => [
                'process' => $process->fresh(),
                'flow' => ['id' => (string) $flow->id, 'name' => $flow->name, 'schedule' => $schedule],
            ],
        ]);
    }

    /**
     * POST /api/systemization/processes/{process}/run
     *
     * Dispatches the compiled runbook now and feeds the result back into
     * the process record (last run, failure streak, escalation).
     */
    public function run(
        Request $request,
        string $processId,
        DagExecutionService $executions,
        ProcessRunRecorder $recorder,
    ): JsonResponse {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $process = BusinessProcess::query()->where('tenant_id', $tenantId)->find($processId);

        if (! $process) {
            return response()->json(['message' => 'Process not found.'], 404);
        }

        if (! $process->flow_id) {
            return response()->json([
                'message' => 'This process has no runbook yet — call automate first.',
            ], 422);
        }

        try {
            $status = $executions->createExecution($tenantId, (string) $process->flow_id, [
                'process_id' => (string) $process->id,
                'triggered_by' => 'systemization.run',
            ]);
        } catch (\Throwable $e) {
            $status = ['execution_id' => null, 'status' => 'failed', 'errors' => [$e->getMessage()]];
        }

        $process = $recorder->record($process, $status);

        return response()->json([
            'data' => [
                'process' => $process,
                'execution' => [
                    'id' => $status['execution_id'] ?? null,
                    'status' => $status['status'] ?? 'unknown',
                ],
            ],
        ]);
    }

    /**
     * POST /api/systemization/processes/{process}/resolve-escalation
     *
     * The human answers the stuck agent's question. The answer resolves the
     * Approval AND becomes a new draft SOP revision — solved forever, per
     * the method: SOP first, then the manager, then update the SOP.
     */
    public function resolveEscalation(Request $request, string $processId, ApprovalEngine $approvals): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $process = BusinessProcess::query()->where('tenant_id', $tenantId)->find($processId);

        if (! $process) {
            return response()->json(['message' => 'Process not found.'], 404);
        }

        if (! $process->needs_attention || ! $process->escalation_approval_id) {
            return response()->json(['message' => 'This process has no open escalation.'], 422);
        }

        $validated = $request->validate([
            'answer' => 'required|string|max:2000',
        ]);

        try {
            $approvals->resolveApproval(
                approvalId: (string) $process->escalation_approval_id,
                approverId: (string) $request->user()->id,
                approved: true,
                response: $validated['answer'],
            );
        } catch (\LogicException) {
            // Already resolved elsewhere — still fold the answer into the SOP.
        }

        $published = Sop::query()
            ->where('process_id', (string) $process->id)
            ->where('status', 'published')
            ->orderByDesc('version')
            ->first();

        $revision = null;

        if ($published) {
            $version = (int) Sop::query()->where('process_id', (string) $process->id)->max('version') + 1;

            $revision = Sop::create([
                'tenant_id' => $tenantId,
                'process_id' => (string) $process->id,
                'version' => $version,
                'title' => $published->title,
                'purpose' => $published->purpose,
                'trigger' => $published->trigger,
                'tools' => $published->tools,
                'steps' => $published->steps,
                'quality_criteria' => $published->quality_criteria,
                'notes' => [
                    ...(array) ($published->notes ?? []),
                    [
                        'answer' => $validated['answer'],
                        'source' => 'escalation',
                        'answered_by' => (string) $request->user()->id,
                        'answered_at' => now()->toIso8601String(),
                    ],
                ],
                'status' => 'draft',
                'created_by' => (string) $request->user()->id,
            ]);
        }

        $process->forceFill([
            'needs_attention' => false,
            'consecutive_failures' => 0,
            'escalation_approval_id' => null,
        ])->save();

        $this->eventStore->append(
            $tenantId,
            'systemization',
            (string) $process->id,
            'systemization.escalation.resolved',
            [
                'process' => $process->name,
                'sop_revision' => $revision?->version,
            ],
        );

        return response()->json([
            'data' => [
                'process' => $process->fresh(),
                'sop_revision' => $revision,
            ],
        ]);
    }

    /**
     * POST /api/systemization/sops/{sop}/publish
     */
    public function publishSop(Request $request, string $sopId): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $sop = Sop::query()
            ->where('tenant_id', $tenantId)
            ->find($sopId);

        if (! $sop) {
            return response()->json(['message' => 'SOP not found.'], 404);
        }

        // Latest published version wins; prior ones are archived.
        Sop::query()
            ->where('process_id', $sop->process_id)
            ->where('status', 'published')
            ->update(['status' => 'archived']);

        $sop->forceFill(['status' => 'published'])->save();

        $this->eventStore->append(
            $tenantId,
            'systemization',
            (string) $sop->id,
            'systemization.sop.published',
            ['process_id' => $sop->process_id, 'version' => $sop->version, 'title' => $sop->title],
        );

        return response()->json(['data' => $sop->fresh()]);
    }
}
