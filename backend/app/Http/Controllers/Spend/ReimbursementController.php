<?php

namespace App\Http\Controllers\Spend;

use App\Http\Controllers\Controller;
use App\Models\Reimbursement;
use App\Services\Spend\ReimbursementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReimbursementController extends Controller
{
    public function __construct(
        private readonly ReimbursementService $reimbursementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $user = $request->user();

        $query = Reimbursement::forTenant($tenant->id)->with('report');

        // Non-admins only see their own payouts.
        if (! $user->atLeastRole('admin')) {
            $query->where('user_id', $user->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        $reimbursements = $query->orderByDesc('created_at')->paginate($request->get('per_page', 20));

        return response()->json(['data' => $reimbursements]);
    }

    public function markPaid(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'reference' => 'sometimes|nullable|string|max:255',
            'method' => 'sometimes|nullable|string|max:30',
        ]);

        try {
            $reimbursement = $this->reimbursementService->markPaid(
                $id,
                $tenant->id,
                $validated['reference'] ?? null,
                $validated['method'] ?? null,
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $reimbursement]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'reason' => 'sometimes|nullable|string',
        ]);

        try {
            $reimbursement = $this->reimbursementService->cancel(
                $id,
                $tenant->id,
                $validated['reason'] ?? null,
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $reimbursement]);
    }
}
