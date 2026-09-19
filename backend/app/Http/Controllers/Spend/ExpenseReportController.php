<?php

namespace App\Http\Controllers\Spend;

use App\Http\Controllers\Controller;
use App\Models\ExpenseItem;
use App\Models\ExpenseReport;
use App\Services\Spend\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseReportController extends Controller
{
    /** Reports at or above this total require a typed confirmation in the UI. */
    private const TYPED_CONFIRM_THRESHOLD = '1000';

    public function __construct(
        private readonly ExpenseService $expenseService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $user = $request->user();
        $scope = $request->get('scope', 'mine');

        $query = ExpenseReport::forTenant($tenant->id)->with('items');

        if ($scope === 'team') {
            if (! $user->atLeastRole('member')) {
                return response()->json([
                    'error' => 'Forbidden',
                    'reason' => 'insufficient_role',
                    'required_role' => 'member',
                ], 403);
            }
        } else {
            $query->forUser($user->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        $reports = $query->orderByDesc('created_at')->paginate($request->get('per_page', 20));

        return response()->json(['data' => $reports]);
    }

    public function summary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $this->expenseService->getSummary($tenant->id)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $report = ExpenseReport::forTenant($tenant->id)
            ->with(['items.category', 'items.receipts', 'reimbursement'])
            ->findOrFail($id);

        $data = $report->toArray();
        $data['line_items'] = $report->items->map(function (ExpenseItem $item) {
            $row = $item->toArray();
            $row['policy_flags'] = $item->policy_flags ?? [];

            return $row;
        })->values();
        $data['receipts'] = $report->items
            ->flatMap(fn (ExpenseItem $item) => $item->receipts)
            ->values();
        $data['requires_typed_confirm'] = bccomp(
            (string) $report->total_amount,
            self::TYPED_CONFIRM_THRESHOLD,
            4
        ) >= 0;

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'currency' => 'sometimes|string|max:10',
            'metadata' => 'sometimes|nullable|array',
            'items' => 'sometimes|array',
            'items.*.description' => 'required|string|max:255',
            'items.*.amount' => 'required|numeric|min:0.01',
            'items.*.expense_date' => 'required|date',
            'items.*.category_id' => 'sometimes|nullable|uuid',
            'items.*.merchant' => 'sometimes|nullable|string|max:255',
            'items.*.currency' => 'sometimes|string|max:10',
            'items.*.gl_account_id' => 'sometimes|nullable|uuid',
        ]);

        $report = $this->expenseService->createReport(
            $tenant->id,
            $request->user()->id,
            $validated,
            $validated['items'] ?? [],
        );

        return response()->json(['data' => $report], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'currency' => 'sometimes|string|max:10',
            'metadata' => 'sometimes|nullable|array',
        ]);

        try {
            $report = $this->expenseService->updateReport($id, $tenant->id, $validated);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $report]);
    }

    public function addItem(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'category_id' => 'sometimes|nullable|uuid',
            'merchant' => 'sometimes|nullable|string|max:255',
            'currency' => 'sometimes|string|max:10',
            'gl_account_id' => 'sometimes|nullable|uuid',
        ]);

        try {
            $item = $this->expenseService->addItem($id, $tenant->id, $validated);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $item], 201);
    }

    public function removeItem(Request $request, string $id, string $itemId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        try {
            $report = $this->expenseService->removeItem($id, $itemId, $tenant->id);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $report]);
    }

    public function attachReceipt(Request $request, string $id, string $itemId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf',
        ]);

        try {
            $document = $this->expenseService->attachReceipt(
                $id,
                $itemId,
                $tenant->id,
                $request->file('file'),
                $request->user()->id,
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $document], 201);
    }

    public function removeReceipt(Request $request, string $id, string $documentId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        try {
            $report = $this->expenseService->removeReceipt($id, $documentId, $tenant->id);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $report]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        try {
            $report = $this->expenseService->submitReport($id, $tenant->id);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $report]);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        try {
            $report = $this->expenseService->voidReport($id, $tenant->id);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $report]);
    }
}
