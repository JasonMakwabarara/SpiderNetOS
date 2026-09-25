<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Models\AgentRun;
use App\Services\Agents\AgentRunService;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/agent-runs — list, start, inspect, trace, cancel, retry and answer
 * the gap questions of runs inside the tenant's agent workspaces.
 */
class AgentRunController extends AgentsController
{
    public function __construct(private readonly AgentRunService $runs) {}

    public function index(Request $request): JsonResponse
    {
        $query = AgentRun::forTenant($this->tenantId($request))->orderByDesc('created_at');

        if (($status = $this->filterValues($request, 'status')) !== []) {
            $query->whereIn('status', $status);
        }
        if (($skill = (string) $request->query('skill', '')) !== '') {
            $query->where('skill_slug', $skill);
        }
        // agent_id / workspace_id are uuid columns: a non-uuid filter matches nothing rather than erroring on Postgres.
        if ((string) $request->query('agent_id', '') !== '') {
            $query->whereIn('agent_id', $this->filterValues($request, 'agent_id', uuid: true));
        }
        if ((string) $request->query('workspace_id', '') !== '') {
            $query->whereIn('workspace_id', $this->filterValues($request, 'workspace_id', uuid: true));
        }

        $page = $query->paginate(max(1, min(100, (int) $request->query('per_page', 20))));
        $page->getCollection()->transform(fn (AgentRun $run) => $this->runPayload($run));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'skill' => 'required|string|max:64',
            'inputs' => 'sometimes|array',
            'agent_id' => 'nullable|string|max:64',
            'trigger_ref' => 'nullable|string|max:190',
            'automation_level' => 'nullable|string|in:human_led,assisted,autonomous',
        ]);

        $inputs = (array) ($validated['inputs'] ?? []);
        if (! empty($validated['agent_id'])) {
            $inputs['agent_id'] = (string) $validated['agent_id'];
        }
        if (! empty($validated['automation_level'])) {
            $inputs['_automation_level'] = (string) $validated['automation_level'];
        }

        try {
            $run = $this->runs->start(
                $this->tenantId($request),
                (string) $validated['skill'],
                $inputs,
                AgentRun::TRIGGER_API,
                $validated['trigger_ref'] ?? null,
                $request->user()?->id ? (string) $request->user()->id : null,
            );
        } catch (AgentRuntimeException $e) {
            return $this->fail($e);
        }

        $data = [
            'run_id' => $run->id,
            'status' => $run->status,
            'workspace_id' => $run->workspace_id,
            'skill_slug' => $run->skill_slug,
            'mode' => $run->mode,
        ];
        if ($run->status === AgentRun::STATUS_BLOCKED) {
            $data['questions'] = (array) ($run->questions ?? []);
        }
        if (! empty(($run->outputs ?? [])['approval_id'])) {
            $data['approval_id'] = $run->outputs['approval_id'];
        }

        return response()->json(['data' => $data], 202);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $run = $this->findRun($request, $id);
        if ($run === null) {
            return response()->json(['error' => 'run_not_found'], 404);
        }

        return response()->json(['data' => $this->runPayload($run, true)]);
    }

    public function trace(Request $request, string $id): JsonResponse
    {
        $run = $this->findRun($request, $id);
        if ($run === null) {
            return response()->json(['error' => 'run_not_found'], 404);
        }

        return response()->json(['data' => [
            'run_id' => $run->id,
            'status' => $run->status,
            'skill_slug' => $run->skill_slug,
            'steps' => $run->steps()->get()->map(fn ($s) => $this->stepPayload($s))->values()->all(),
        ]]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $run = $this->findRun($request, $id);
        if ($run === null) {
            return response()->json(['error' => 'run_not_found'], 404);
        }
        if ($run->isTerminal()) {
            return response()->json(['error' => 'run_already_finished', 'status' => $run->status], 409);
        }

        $run = $this->runs->cancel($run, $request->user()?->id ? (string) $request->user()->id : null, (string) $request->input('reason', ''));

        return response()->json(['data' => $this->runPayload($run)]);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $run = $this->findRun($request, $id);
        if ($run === null) {
            return response()->json(['error' => 'run_not_found'], 404);
        }

        try {
            $run = $this->runs->retry($run, $request->user()?->id ? (string) $request->user()->id : null);
        } catch (AgentRuntimeException $e) {
            return $this->fail($e);
        }

        return response()->json(['data' => $this->runPayload($run)], 202);
    }

    public function answers(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'answers' => 'required|array|min:1',
            'answers.*.path' => 'required|string|max:255',
            'answers.*.section' => 'nullable|string|max:120',
            'answers.*.text' => 'required|string|max:20000',
        ]);

        $run = $this->findRun($request, $id);
        if ($run === null) {
            return response()->json(['error' => 'run_not_found'], 404);
        }

        try {
            $run = $this->runs->answer($run, (array) $validated['answers'], $request->user()?->id ? (string) $request->user()->id : null);
        } catch (AgentRuntimeException $e) {
            return $this->fail($e);
        }

        return response()->json(['data' => $this->runPayload($run)], $run->status === AgentRun::STATUS_BLOCKED ? 200 : 202);
    }
}
