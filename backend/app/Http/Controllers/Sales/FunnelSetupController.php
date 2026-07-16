<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesScript;
use App\Services\DeploymentReadinessService;
use App\Services\Sales\FunnelSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FunnelSetupController extends Controller
{
    public function __construct(
        private readonly FunnelSetupService $funnelSetupService,
    ) {}

    /**
     * GET /api/sales/readiness — tenant-scoped go-live gaps, Hannah guidance
     * pattern. See App\Services\DeploymentReadinessService.
     */
    public function readiness(Request $request, DeploymentReadinessService $readiness): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $readiness->tenantChecks($tenant->id)]);
    }

    public function show(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $setup = $this->funnelSetupService->getOrCreate($tenant->id);
        $next = $this->funnelSetupService->nextQuestion($setup);

        return response()->json(['data' => [
            'funnel_setup' => $setup->load('activeScript'),
            'next' => $next,
        ]]);
    }

    public function start(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $setup = $this->funnelSetupService->getOrCreate($tenant->id);
        $setup = $this->funnelSetupService->beginInterview($setup);

        return response()->json(['data' => [
            'funnel_setup' => $setup,
            'next' => $this->funnelSetupService->nextQuestion($setup),
        ]]);
    }

    public function answer(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'question_id' => 'required|string|max:64',
            'answer' => 'required|string|max:2000',
        ]);

        $setup = $this->funnelSetupService->getOrCreate($tenant->id);
        if ($setup->status !== 'interviewing') {
            return response()->json(['message' => 'Funnel setup is not in the interviewing stage.'], 409);
        }

        $setup = $this->funnelSetupService->recordAnswer($setup, $validated['question_id'], $validated['answer']);

        return response()->json(['data' => [
            'funnel_setup' => $setup,
            'next' => $this->funnelSetupService->nextQuestion($setup),
        ]]);
    }

    public function draftScript(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $setup = $this->funnelSetupService->getOrCreate($tenant->id);

        if ($setup->status !== 'interviewing') {
            return response()->json(['message' => 'Complete the discovery interview before drafting a script.'], 409);
        }

        $next = $this->funnelSetupService->nextQuestion($setup);
        if (! $next['done']) {
            return response()->json(['message' => 'Discovery interview is not complete yet.', 'next' => $next], 409);
        }

        $script = $this->funnelSetupService->draftScript($setup);

        return response()->json(['data' => ['script' => $script]], 201);
    }

    public function submitScript(Request $request, string $scriptId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $script = SalesScript::forTenant($tenant->id)->findOrFail($scriptId);
        $setup = $this->funnelSetupService->getOrCreate($tenant->id);

        if ($script->status !== 'draft') {
            return response()->json(['message' => "Script is already {$script->status}."], 409);
        }

        $approval = $this->funnelSetupService->submitForApproval($setup, $script, (string) $request->user()->id);

        return response()->json(['data' => ['approval' => $approval]]);
    }

    public function reviseScript(Request $request, string $scriptId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $script = SalesScript::forTenant($tenant->id)->findOrFail($scriptId);

        $validated = $request->validate([
            'sections' => 'required|array',
        ]);

        $script = $this->funnelSetupService->reviseScript($this->funnelSetupService->getOrCreate($tenant->id), $script, $validated['sections']);

        return response()->json(['data' => ['script' => $script]]);
    }

    public function requestRevision(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $setup = $this->funnelSetupService->getOrCreate($tenant->id);

        if (! in_array($setup->status, ['awaiting_approval', 'rejected'], true)) {
            return response()->json(['message' => "Cannot request a revision from status {$setup->status}."], 409);
        }

        $setup = $this->funnelSetupService->requestRevision($setup);

        return response()->json(['data' => ['funnel_setup' => $setup]]);
    }
}
