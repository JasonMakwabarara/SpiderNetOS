<?php

declare(strict_types=1);

namespace App\Http\Controllers\Founder;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ApprovalReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /api/approvals/{id}/review (plan D8 #8): the cockpit records how an
 * approval was actually reviewed — dwell time, whether the diff was
 * expanded, whether the body was edited, and the decision — so the
 * promotion gate can count real reviews rather than clicks.
 */
class ApprovalReviewController extends Controller
{
    public function store(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $approval = Str::isUuid($id) ? Approval::forTenant($tenantId)->find($id) : null;
        if ($approval === null) {
            return response()->json(['message' => 'Approval not found.'], 404);
        }

        $v = $request->validate([
            'dwell_ms' => 'sometimes|integer|min:0|max:86400000',
            'diff_expanded' => 'sometimes|boolean',
            'edited' => 'sometimes|boolean',
            'decision' => 'sometimes|nullable|string|in:'.implode(',', ApprovalReview::DECISIONS),
            'meta' => 'sometimes|array',
        ]);

        $review = ApprovalReview::create([
            'tenant_id' => $tenantId,
            'approval_id' => (string) $approval->id,
            'user_id' => (string) $request->user()->id,
            'dwell_ms' => (int) ($v['dwell_ms'] ?? 0),
            'diff_expanded' => (bool) ($v['diff_expanded'] ?? false),
            'edited' => (bool) ($v['edited'] ?? false),
            'decision' => $v['decision'] ?? null,
            'meta' => (array) ($v['meta'] ?? []),
        ]);

        return response()->json(['data' => [
            'id' => $review->id,
            'approval_id' => $review->approval_id,
            'user_id' => $review->user_id,
            'dwell_ms' => $review->dwell_ms,
            'diff_expanded' => $review->diff_expanded,
            'edited' => $review->edited,
            'decision' => $review->decision,
            'created_at' => $review->created_at?->toIso8601String(),
        ]], 201);
    }
}
