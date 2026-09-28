<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Services\Agents\Exceptions\BundleIntegrityException;
use App\Services\ApprovalActions;
use App\Services\ApprovalAlreadyDecided;
use App\Services\ApprovalEngine;
use App\Services\ApprovalVersionConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    /**
     * Single approval with its chain steps (empty for legacy single-stage).
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $approval = Approval::forTenant($tenantId)->with('steps')->find($id);

        if (! $approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        return response()->json(['data' => $approval]);
    }

    /**
     * Delegate the current pending chain step to another tenant user.
     */
    public function delegate(Request $request, $id): JsonResponse
    {
        $request->validate([
            'to_user_id' => 'required|uuid',
            'note' => 'nullable|string|max:1000',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $approval = DB::table('approvals')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        try {
            $result = app(ApprovalEngine::class)->delegateStep(
                $id,
                (string) $request->user()?->id,
                $request->input('to_user_id'),
                (string) $request->input('note', ''),
            );
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * List approvals scoped to tenant, with optional status filter.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $query = DB::table('approvals')
            ->where('tenant_id', $tenantId);

        // Optional status filter
        if ($request->has('status')) {
            $request->validate([
                'status' => 'string|in:pending,approved,rejected,expired',
            ]);
            $query->where('status', $request->input('status'));
        }

        $approvals = $query
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($approvals);
    }

    /**
     * Approve a pending approval via EventStore (Hard Rule #1).
     * Resumes blocked DAG node if applicable.
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:2000',
            // The version the approver was shown; required for resource types
            // with a version binding (ApprovalEngine::decideSingleStage).
            'version_hash' => 'nullable|string|max:80',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $approval = DB::table('approvals')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        // A fast path for an obvious replay. It decides nothing — see decide().
        if ($approval->status !== 'pending') {
            return response()->json([
                'error' => "Approval has already been {$approval->status}.",
            ], 409);
        }

        // Multi-stage chains resolve through the engine (per-step auth,
        // step advancement, terminal hooks, events).
        if ($approval->current_step !== null) {
            return $this->resolveChainStep($request, $id, true, (string) $request->input('reason', ''), $request->input('version_hash'));
        }

        return $this->decide($request, $tenantId, $id, granted: true);
    }

    /**
     * Reject a pending approval via EventStore (Hard Rule #1).
     * Fails blocked DAG node if applicable.
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $approval = DB::table('approvals')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        // A fast path for an obvious replay. It decides nothing — see decide().
        if ($approval->status !== 'pending') {
            return response()->json([
                'error' => "Approval has already been {$approval->status}.",
            ], 409);
        }

        // Multi-stage chains resolve through the engine (see approve()).
        if ($approval->current_step !== null) {
            return $this->resolveChainStep($request, $id, false, (string) $request->input('reason', ''));
        }

        return $this->decide($request, $tenantId, $id, granted: false);
    }

    /**
     * A single-stage decision, made by ApprovalEngine::decideSingleStage() —
     * the one operation every caller uses, which authorises the actor,
     * transitions only a pending approval, records the decision with it and
     * fires the resource hook after commit. This translates its outcome into
     * HTTP and nothing more; the rule itself lives in one place.
     */
    private function decide(Request $request, string $tenantId, string $id, bool $granted): JsonResponse
    {
        try {
            $decision = app(ApprovalEngine::class)->decideSingleStage(
                $tenantId, $id, $request->user(), $granted, $request->input('reason'), $request->input('version_hash'),
            );
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Approval not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ApprovalAlreadyDecided $e) {
            // The loser of a race, or a replay: the answer already given.
            return response()->json(['error' => "Approval has already been {$e->status}."], 409);
        } catch (ApprovalVersionConflict $e) {
            // Deliberately without the current version: a client must show it
            // to the approver again, not resend it unseen.
            return response()->json(['error' => $e->getMessage(), 'reason' => 'version_'.$e->reason], 409);
        } catch (BundleIntegrityException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => 'bundle_integrity'], 409);
        }

        // The decision is final either way. 202 says its effect is not done
        // yet: retried by recovery (pending), or waiting on a person (failed,
        // uncertain). The reason stays in the action record and the log.
        return response()->json([
            'id' => $id,
            'event_id' => $decision->event->id,
            'status' => $granted ? 'approved' : 'rejected',
            'action' => ['id' => $decision->actionId, 'status' => $decision->actionStatus],
            'message' => ($granted ? 'Approval granted.' : 'Approval rejected.').match ($decision->actionStatus) {
                ApprovalActions::DONE => '',
                ApprovalActions::PENDING => ' Its effect has not completed and is waiting to be retried.',
                ApprovalActions::FAILED => ' Its effect could not be applied and needs attention.',
                default => ' Whether its effect happened is not known; it needs checking.',
            },
        ], $decision->settled() ? 200 : 202);
    }

    /**
     * Route a chained approval through ApprovalEngine::resolveStep with
     * HTTP error mapping.
     */
    private function resolveChainStep(Request $request, string $id, bool $approved, string $response, ?string $presentedVersion = null): JsonResponse
    {
        try {
            $result = app(ApprovalEngine::class)->resolveStep(
                $id,
                (string) $request->user()?->id,
                $approved,
                $response,
                $presentedVersion,
            );
        } catch (ApprovalVersionConflict $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => 'version_'.$e->reason], 409);
        } catch (BundleIntegrityException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => 'bundle_integrity'], 409);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json([
            'id' => $id,
            'status' => $result['status'],
            'current_step' => $result['current_step'],
            'message' => $approved ? 'Step approved.' : 'Approval rejected.',
        ]);
    }
}
