<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Shared serialisation + error mapping for the runtime controllers.
 */
abstract class AgentsController extends Controller
{
    protected function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('tenant_id');
    }

    protected function fail(AgentRuntimeException $e): JsonResponse
    {
        return response()->json($e->toResponse(), $e->httpStatus);
    }

    /**
     * Tenant-scoped run lookup that never queries a uuid column with a
     * non-uuid value: Postgres raises `invalid input syntax for type uuid`
     * (and aborts the surrounding transaction) where sqlite just finds nothing.
     */
    protected function findRun(Request $request, string $id): ?AgentRun
    {
        return Str::isUuid($id) ? AgentRun::forTenant($this->tenantId($request))->find($id) : null;
    }

    protected function findArtifact(Request $request, string $id): ?AgentArtifact
    {
        return Str::isUuid($id) ? AgentArtifact::forTenant($this->tenantId($request))->find($id) : null;
    }

    /**
     * Comma-separated query filter values; when the column is a uuid, drop
     * anything that is not one so the query stays valid on every driver (an
     * all-invalid filter matches nothing, which is what "no such id" means).
     *
     * @return list<string>
     */
    protected function filterValues(Request $request, string $key, bool $uuid = false): array
    {
        $values = array_values(array_filter(array_map('trim', explode(',', (string) $request->query($key, ''))), fn (string $v): bool => $v !== ''));
        if ($uuid) {
            $values = array_values(array_filter($values, fn (string $v): bool => Str::isUuid($v)));
        }

        return $values;
    }

    /** @return array<string, mixed> */
    protected function runPayload(AgentRun $run, bool $withDetails = false): array
    {
        $state = (array) ($run->state ?? []);
        $pending = (array) ($state['pending_tool_call'] ?? []);

        $data = [
            'id' => $run->id,
            'run_id' => $run->id,
            'tenant_id' => $run->tenant_id,
            'workspace_id' => $run->workspace_id,
            'agent_id' => $run->agent_id,
            'skill_slug' => $run->skill_slug,
            'mode' => $run->mode,
            'trigger_type' => $run->trigger_type,
            'trigger_ref' => $run->trigger_ref,
            'triggered_by' => $run->triggered_by,
            'status' => $run->status,
            'inputs' => (array) ($run->inputs ?? []),
            'outputs' => (array) ($run->outputs ?? []),
            'questions' => (array) ($run->questions ?? []),
            'next_steps' => $run->nextSteps(),
            'approval_id' => ($run->outputs ?? [])['approval_id'] ?? ($pending['approval_id'] ?? null),
            'pending_tool_call' => $pending !== [] ? [
                'tool' => $pending['tool'] ?? null,
                'risk' => $pending['risk'] ?? null,
                'approval_id' => $pending['approval_id'] ?? null,
                'decision' => $pending['decision'] ?? null,
            ] : null,
            'brain_snapshot_hash' => $state['brain_snapshot_hash'] ?? null,
            'tokens' => (int) $run->tokens,
            'cost_usd' => (float) $run->cost_usd,
            'error' => $run->error,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];

        if ($withDetails) {
            $data['brain_snapshot'] = (array) ($run->brain_snapshot ?? []);
            $data['steps'] = $run->steps()->get()->map(fn (AgentRunStep $s) => $this->stepPayload($s))->values()->all();
            $data['artifacts'] = $run->artifacts()->orderBy('created_at')->get()
                ->map(fn (AgentArtifact $a) => $this->artifactPayload($a, false))->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    protected function stepPayload(AgentRunStep $step): array
    {
        return [
            'id' => $step->id,
            'seq' => (int) $step->seq,
            'kind' => $step->kind,
            'name' => $step->name,
            'status' => $step->status,
            'input' => (array) ($step->input ?? []),
            'output' => (array) ($step->output ?? []),
            'tokens' => (int) $step->tokens,
            'cost_usd' => (float) $step->cost_usd,
            'duration_ms' => $step->duration_ms,
            'created_at' => $step->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function artifactPayload(AgentArtifact $artifact, bool $withContent = true): array
    {
        $meta = (array) ($artifact->meta ?? []);

        return [
            'id' => $artifact->id,
            'run_id' => $artifact->run_id,
            'workspace_id' => $artifact->workspace_id,
            'skill_slug' => $artifact->skill_slug,
            'kind' => $artifact->kind,
            'path' => $artifact->path,
            'title' => $artifact->title,
            'status' => $artifact->status,
            'approval_id' => $artifact->approval_id,
            'applied_ref' => $artifact->applied_ref,
            'edited' => isset($meta['original_content']) && $meta['original_content'] !== (string) $artifact->content,
            'preview' => mb_substr((string) $artifact->content, 0, 280),
            'content' => $withContent ? (string) $artifact->content : null,
            'meta' => $withContent ? $meta : array_diff_key($meta, ['original_content' => true, 'steps' => true]),
            'submitted_at' => $artifact->submitted_at?->toIso8601String(),
            'approved_at' => $artifact->approved_at?->toIso8601String(),
            'applied_at' => $artifact->applied_at?->toIso8601String(),
            'created_at' => $artifact->created_at?->toIso8601String(),
            'updated_at' => $artifact->updated_at?->toIso8601String(),
        ];
    }
}
