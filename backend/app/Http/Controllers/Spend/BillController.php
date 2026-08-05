<?php

namespace App\Http\Controllers\Spend;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Services\Spend\BillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController extends Controller
{
    /** Bills at or above this total require a typed confirmation in the UI. */
    private const TYPED_CONFIRM_THRESHOLD = '1000';

    public function __construct(
        private readonly BillService $billService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $query = Bill::forTenant($tenant->id)->with('vendor');

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->get('vendor_id'));
        }

        $bills = $query->orderByDesc('created_at')->paginate($request->get('per_page', 20));

        return response()->json(['data' => $bills]);
    }

    public function summary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $this->billService->getSummary($tenant->id)]);
    }

    public function dueSoon(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $days = (int) $request->get('days', 7);

        return response()->json(['data' => $this->billService->getDueSoon($tenant->id, $days)]);
    }

    public function aging(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $this->billService->getAging($tenant->id)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $bill = Bill::forTenant($tenant->id)
            ->with(['vendor', 'lineItems', 'documents', 'payment'])
            ->findOrFail($id);

        $data = $bill->toArray();
        $data['line_items'] = $bill->lineItems->values();
        $data['requires_typed_confirm'] = bccomp(
            (string) $bill->total_amount,
            self::TYPED_CONFIRM_THRESHOLD,
            4
        ) >= 0;
        $data['payment'] = [
            'method' => $bill->payment?->method,
            'scheduled_for' => $bill->scheduled_for?->toDateString(),
            'paid_at' => $bill->paid_at?->toDateString(),
            'bank_reference' => $bill->payment?->provider_reference,
        ];

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'vendor_id' => 'sometimes|nullable|uuid',
            'vendor_invoice_ref' => 'sometimes|nullable|string|max:255',
            'currency' => 'sometimes|string|max:10',
            'issue_date' => 'sometimes|nullable|date',
            'due_date' => 'required|date',
            'notes' => 'sometimes|nullable|string',
            'terms' => 'sometimes|nullable|string',
            'metadata' => 'sometimes|nullable|array',
            'lines' => 'sometimes|array',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'sometimes|numeric|min:0.0001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate' => 'sometimes|numeric|min:0|max:100',
            'lines.*.category_id' => 'sometimes|nullable|uuid',
            'lines.*.gl_account_id' => 'sometimes|nullable|uuid',
        ]);

        $bill = $this->billService->createBill(
            $tenant->id,
            $validated,
            $validated['lines'] ?? [],
        );

        return response()->json(['data' => $bill], 201);
    }

    public function upload(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf',
        ]);

        $bill = $this->billService->ingestUploadedBill(
            $tenant->id,
            $request->file('file'),
            $request->user()->id,
        );

        return response()->json(['data' => $bill], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'vendor_id' => 'sometimes|nullable|uuid',
            'vendor_invoice_ref' => 'sometimes|nullable|string|max:255',
            'currency' => 'sometimes|string|max:10',
            'issue_date' => 'sometimes|nullable|date',
            'due_date' => 'sometimes|date',
            'notes' => 'sometimes|nullable|string',
            'terms' => 'sometimes|nullable|string',
            'metadata' => 'sometimes|nullable|array',
            'lines' => 'sometimes|array',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'sometimes|numeric|min:0.0001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate' => 'sometimes|numeric|min:0|max:100',
            'lines.*.category_id' => 'sometimes|nullable|uuid',
            'lines.*.gl_account_id' => 'sometimes|nullable|uuid',
        ]);

        try {
            $bill = $this->billService->updateBill(
                $id,
                $tenant->id,
                $validated,
                array_key_exists('lines', $validated) ? $validated['lines'] : null,
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $bill]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        try {
            $bill = $this->billService->submitForApproval($id, $tenant->id, $request->user()->id);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $bill]);
    }

    public function schedule(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        try {
            $bill = $this->billService->schedulePayment($id, $tenant->id, $validated['date']);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $bill]);
    }

    public function markPaid(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'bank_reference' => 'sometimes|nullable|string|max:255',
            'method' => 'sometimes|string|max:30',
        ]);

        try {
            $bill = $this->billService->markPaid(
                $id,
                $tenant->id,
                $validated['bank_reference'] ?? null,
                $validated['method'] ?? 'bank_transfer',
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $bill]);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'reason' => 'sometimes|nullable|string|max:1000',
        ]);

        try {
            $bill = $this->billService->voidBill($id, $tenant->id, $validated['reason'] ?? null);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $bill]);
    }
}
