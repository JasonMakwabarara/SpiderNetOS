<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\SequenceEnrollment;
use App\Services\Messaging\MessageDispatchService;
use App\Services\Sales\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Called by the Python intelligence workers (crm dynamic agent) when a DAG
 * flow node needs to score or transition a lead — e.g. the "score" node in
 * packages/feature-packs/sales-crm/flows/lead-capture.dag.yaml.
 *
 * Guarded by internal.key middleware, not Sanctum: workers authenticate with
 * a shared key over the private network and pass tenant scope explicitly via
 * X-Tenant-Id, since there is no logged-in user in this call path.
 */
class SalesController extends Controller
{
    public function __construct(
        private readonly LeadService $leadService,
    ) {}

    public function updateStage(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->header('X-Tenant-Id', '');
        if ($tenantId === '') {
            return response()->json(['message' => 'X-Tenant-Id header is required.'], 422);
        }

        $lead = Lead::forTenant($tenantId)->find($id);
        if (! $lead) {
            return response()->json(['message' => 'Lead not found.'], 404);
        }

        $validated = $request->validate([
            'stage' => 'required|string|in:'.implode(',', Lead::STAGES),
            'context' => 'sometimes|array',
        ]);

        try {
            $lead = $this->leadService->transitionStage($lead, $validated['stage'], $validated['context'] ?? []);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $lead->id, 'stage' => $lead->stage, 'score' => $lead->score]]);
    }

    public function updateScore(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->header('X-Tenant-Id', '');
        if ($tenantId === '') {
            return response()->json(['message' => 'X-Tenant-Id header is required.'], 422);
        }

        $lead = Lead::forTenant($tenantId)->find($id);
        if (! $lead) {
            return response()->json(['message' => 'Lead not found.'], 404);
        }

        $validated = $request->validate([
            'score' => 'required|integer|min:0|max:100',
        ]);

        $lead->update(['score' => $validated['score']]);

        return response()->json(['data' => ['id' => $lead->id, 'score' => $lead->score]]);
    }

    /**
     * Send a message (or a rendered template) to a lead on email or WhatsApp.
     * Backs the crm agent's send_email/send_whatsapp tools
     * (intelligence/tools/registry.py).
     */
    public function sendMessage(Request $request, string $id, MessageDispatchService $dispatch): JsonResponse
    {
        $tenantId = (string) $request->header('X-Tenant-Id', '');
        if ($tenantId === '') {
            return response()->json(['message' => 'X-Tenant-Id header is required.'], 422);
        }

        $lead = Lead::forTenant($tenantId)->find($id);
        if (! $lead) {
            return response()->json(['message' => 'Lead not found.'], 404);
        }

        $validated = $request->validate([
            'channel' => 'required|string|in:email,whatsapp',
            'template_key' => 'sometimes|string|max:64',
            'body' => 'sometimes|string|max:4000',
            'subject' => 'sometimes|string|max:255',
            'sent_by' => 'sometimes|string|max:64',
        ]);

        if (empty($validated['template_key']) && empty($validated['body'])) {
            return response()->json(['message' => 'Provide either template_key or body.'], 422);
        }

        $result = isset($validated['template_key'])
            ? $dispatch->sendTemplate($lead, $validated['channel'], $validated['template_key'])
            : $dispatch->send($lead, $validated['channel'], $validated['body'], $validated['subject'] ?? null, null, $validated['sent_by'] ?? 'crm');

        return response()->json(['data' => $result], $result['success'] ? 200 : 422);
    }

    /**
     * Enrolls a lead in the pack's nurture sequence (see flows/nurture-sequence.yaml),
     * consumed by Jobs\ProcessSequenceStepsJob. Backs the crm agent's
     * enroll_in_sequence action on the medium-score branch of lead-capture.dag.yaml.
     */
    public function enrollInSequence(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->header('X-Tenant-Id', '');
        if ($tenantId === '') {
            return response()->json(['message' => 'X-Tenant-Id header is required.'], 422);
        }

        $lead = Lead::forTenant($tenantId)->find($id);
        if (! $lead) {
            return response()->json(['message' => 'Lead not found.'], 404);
        }

        $validated = $request->validate([
            'sequence_key' => 'sometimes|string|max:64',
        ]);
        $sequenceKey = $validated['sequence_key'] ?? 'nurture-sequence';

        $enrollment = SequenceEnrollment::updateOrCreate(
            ['lead_id' => $lead->id, 'sequence_key' => $sequenceKey],
            ['tenant_id' => $tenantId, 'current_step' => 0, 'status' => 'active', 'next_run_at' => now(), 'context' => []],
        );

        return response()->json(['data' => ['id' => $enrollment->id, 'status' => $enrollment->status]], 201);
    }
}
