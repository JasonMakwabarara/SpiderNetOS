<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Cashbook;
use App\Models\CashMovement;
use App\Models\CreditNote;
use App\Models\Employee;
use App\Models\FiscalDevice;
use App\Models\FiscalSubmission;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Services\Enterprise\CashManagementService;
use App\Services\Enterprise\CreditNoteService;
use App\Services\Enterprise\FiscalisationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceOpsController extends Controller
{
    public function __construct(
        private readonly CashManagementService $cash,
        private readonly CreditNoteService $creditNotes,
        private readonly FiscalisationService $fiscal,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        return response()->json(['data' => [
            'employees_active' => Employee::forTenant($tenantId)->where('status', 'active')->count(),
            'clock_events' => \App\Models\ClockEvent::forTenant($tenantId)->count(),
            'requisitions_submitted' => Requisition::forTenant($tenantId)->where('status', 'submitted')->count(),
            'purchase_orders_open' => PurchaseOrder::forTenant($tenantId)->where('status', 'draft')->count(),
            'assets_unassigned' => Asset::forTenant($tenantId)->where('status', 'available')->count(),
            'invoices_open' => Invoice::forTenant($tenantId)->whereIn('status', ['draft', 'sent', 'overdue'])->count(),
            'cashbooks' => Cashbook::forTenant($tenantId)->count(),
            'fiscal_pending' => Invoice::forTenant($tenantId)->where('fiscal_status', 'pending')->count(),
        ]]);
    }

    public function indexCashbooks(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Cashbook::forTenant((string) $request->attributes->get('tenant_id'))->orderBy('name')->get(),
        ]);
    }

    public function storeCashbook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'currency' => 'required|string|max:4',
        ]);
        $book = $this->cash->createCashbook(
            (string) $request->attributes->get('tenant_id'),
            $data['name'],
            $data['currency'],
        );

        return response()->json(['data' => $book], 201);
    }

    public function showCashbook(Request $request, string $id): JsonResponse
    {
        $book = Cashbook::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $book]);
    }

    public function indexMovements(Request $request): JsonResponse
    {
        $rows = CashMovement::forTenant((string) $request->attributes->get('tenant_id'))
            ->orderByDesc('movement_date')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cashbook_id' => 'required|uuid',
            'type' => 'required|in:receipt,payment',
            'amount' => 'required|numeric',
            'currency' => 'required|string|max:4',
            'movement_date' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'invoice_id' => 'nullable|uuid',
        ]);
        $movement = $this->cash->recordMovement((string) $request->attributes->get('tenant_id'), $data);

        return response()->json(['data' => $movement], 201);
    }

    public function showMovement(Request $request, string $id): JsonResponse
    {
        $movement = CashMovement::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $movement]);
    }

    public function indexCreditNotes(Request $request): JsonResponse
    {
        $rows = CreditNote::forTenant((string) $request->attributes->get('tenant_id'))
            ->with('lines')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function storeCreditNote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => 'required|uuid',
            'currency' => 'nullable|string|max:4',
            'reason' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|gte:0',
            'lines.*.tax_rate' => 'nullable|numeric|gte:0',
        ]);
        $note = $this->creditNotes->create((string) $request->attributes->get('tenant_id'), $data);

        return response()->json(['data' => $note], 201);
    }

    public function showCreditNote(Request $request, string $id): JsonResponse
    {
        $note = CreditNote::forTenant((string) $request->attributes->get('tenant_id'))
            ->with('lines')
            ->findOrFail($id);

        return response()->json(['data' => $note]);
    }

    public function issueCreditNote(Request $request, string $id): JsonResponse
    {
        $note = $this->creditNotes->issue((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $note]);
    }

    public function indexDevices(Request $request): JsonResponse
    {
        return response()->json([
            'data' => FiscalDevice::forTenant((string) $request->attributes->get('tenant_id'))->orderBy('name')->get(),
        ]);
    }

    public function storeDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'device_identifier' => 'required|string|max:64',
            'status' => 'nullable|in:active,inactive',
            'credentials' => 'nullable|string|max:2000',
        ]);
        $device = $this->fiscal->registerDevice((string) $request->attributes->get('tenant_id'), $data);

        return response()->json(['data' => $device], 201);
    }

    public function showDevice(Request $request, string $id): JsonResponse
    {
        $device = FiscalDevice::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $device]);
    }

    public function indexSubmissions(Request $request): JsonResponse
    {
        $rows = FiscalSubmission::forTenant((string) $request->attributes->get('tenant_id'))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function showSubmission(Request $request, string $id): JsonResponse
    {
        $row = FiscalSubmission::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $row]);
    }

    public function fiscalise(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate(['device_id' => 'required|uuid']);
        $submission = $this->fiscal->fiscalise(
            (string) $request->attributes->get('tenant_id'),
            $invoice,
            $data['device_id'],
        );

        return response()->json(['data' => $submission]);
    }
}
