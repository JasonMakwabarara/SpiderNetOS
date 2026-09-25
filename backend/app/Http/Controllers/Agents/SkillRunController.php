<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Models\AgentRun;
use App\Services\Agents\AgentRunService;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use App\Services\Agents\Exceptions\PackNotEntitledException;
use App\Services\Agents\RunContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/skills/{slug}/run (route in routes/api/skills.php, Stream B1).
 *
 *   200/202 {run_id, kind, mode, status, approval_id?, entry_path, run_path, questions?}
 *   402     {message, checkout_hint, pack_id, amount_cents, currency}  pack not entitled
 *   422     {missing_brain: [{path, section, question}], run_id, ...}  blocked at start
 *
 * `route` kinds open a cockpit view (no run); other non-agent kinds are
 * handled by their own controllers and answer 409 here.
 */
class SkillRunController extends AgentsController
{
    public function __construct(
        private readonly AgentRunService $runs,
        private readonly RunContextFactory $contexts,
    ) {}

    public function run(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'inputs' => 'sometimes|array',
            'automation_level' => 'nullable|string|in:human_led,assisted,autonomous',
            'agent_id' => 'nullable|string|max:64',
            'trigger_ref' => 'nullable|string|max:190',
        ]);

        try {
            $card = $this->contexts->card($slug);
        } catch (AgentRuntimeException $e) {
            return $this->fail($e);
        }

        $kind = $card->runKind();
        $entryPath = $card->entryPath() ?? '/skills/'.$card->id();

        if ($kind === 'route') {
            return response()->json(['run_id' => null, 'kind' => $kind, 'mode' => $card->mode(), 'entry_path' => $entryPath]);
        }
        if ($kind !== 'agent') {
            return response()->json([
                'error' => 'run_kind_not_supported',
                'message' => "Skill [{$card->id()}] runs as [{$kind}]; use its own entry point.",
                'kind' => $kind,
                'entry_path' => $entryPath,
            ], 409);
        }

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
                $card->id(),
                $inputs,
                AgentRun::TRIGGER_MANUAL,
                $validated['trigger_ref'] ?? null,
                $request->user()?->id ? (string) $request->user()->id : null,
            );
        } catch (PackNotEntitledException $e) {
            return response()->json(['message' => $e->getMessage()] + $e->extra, 402);
        } catch (AgentRuntimeException $e) {
            return $this->fail($e);
        }

        $payload = [
            'run_id' => $run->id,
            'kind' => $kind,
            'mode' => $run->mode,
            'status' => $run->status,
            'workspace_id' => $run->workspace_id,
            'entry_path' => $entryPath,
            'run_path' => '/agents/runs/'.$run->id,
        ];

        if ($run->status === AgentRun::STATUS_BLOCKED) {
            return response()->json($payload + ['missing_brain' => (array) ($run->questions ?? []), 'questions' => (array) ($run->questions ?? [])], 422);
        }

        $approvalId = ($run->outputs ?? [])['approval_id'] ?? (($run->state ?? [])['pending_tool_call']['approval_id'] ?? null);
        if ($approvalId) {
            $payload['approval_id'] = $approvalId;
        }
        if ($run->status === AgentRun::STATUS_FAILED) {
            $payload['error'] = $run->error;
        }

        return response()->json($payload, $run->isTerminal() ? 200 : 202);
    }
}
