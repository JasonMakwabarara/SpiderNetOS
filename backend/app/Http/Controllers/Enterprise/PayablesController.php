<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\Cashbook;
use App\Models\CreditNote;
use App\Models\FiscalSubmission;
use App\Models\Invoice;
use App\Models\PayablesPosting;
use App\Models\ThreeWayMatch;
use App\Services\Enterprise\CashManagementService;
use App\Services\Enterprise\CreditNoteService;
use App\Services\Enterprise\FiscalisationService;
use App\Services\Enterprise\PayablesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayablesController extends Controller
{
    public function __construct(
        private readonly PayablesService $payables,
        private readonly CashManagementService $cash,
        private readonly FiscalisationService $fiscal,
        private readonly CreditNoteService $credits,
    ) {}

    public function invoiceFromPurchaseOrder(Request $request, string $id): JsonResponse
    {
        $invoice = $this->payables->invoiceFromPurchaseOrder(
            (string) $request->attributes->get('tenant_id'),
            $id,
        );

        return response()->json(['data' => $invoice], 201);
    }

    public function showSupplierInvoice(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $invoice = Invoice::forTenant($tenantId)->with('lineItems')->findOrFail($id);

        return response()->json([
            'data' => $invoice,
            'match' => ThreeWayMatch::forTenant($tenantId)->where('invoice_id', $invoice->id)->with('lines')->first(),
            'posting' => PayablesPosting::forTenant($tenantId)->where('invoice_id', $invoice->id)->first(),
            'fiscal' => FiscalSubmission::forTenant($tenantId)->where('invoice_id', $invoice->id)->first(),
            'credit_notes' => CreditNote::forTenant($tenantId)->where('invoice_id', $invoice->id)->with('lines')->orderBy('credit_note_number')->get(),
        ]);
    }

    public function storeCreditNote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:2000',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|gt:0',
        ]);
        $note = $this->credits->create(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['lines'],
            $data['reason'] ?? null,
        );

        return response()->json(['data' => $note], 201);
    }

    public function storeSalesCreditNote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.invoice_line_item_id' => 'required|uuid',
            'lines.*.quantity' => 'required|numeric|gt:0',
        ]);
        $note = $this->credits->createForSalesInvoice(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['lines'],
            $data['reason'],
        );

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
        $note = $this->credits->issue((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $note]);
    }

    public function match(Request $request, string $id): JsonResponse
    {
        $match = $this->payables->match((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $match]);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $posting = $this->payables->post((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $posting], 201);
    }

    public function indexCashbooks(Request $request): JsonResponse
    {
        $rows = Cashbook::forTenant((string) $request->attributes->get('tenant_id'))->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function storeCashbook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'currency' => 'required|string|max:4',
        ]);
        $cashbook = $this->cash->createCashbook(
            (string) $request->attributes->get('tenant_id'),
            $data['name'],
            $data['currency'],
        );

        return response()->json(['data' => $cashbook], 201);
    }

    public function storeMovement(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:receipt,payment',
            'amount' => 'required|numeric|gt:0',
            'currency' => 'required|string|max:4',
            'movement_date' => 'required|date',
            'invoice_id' => 'nullable|uuid',
        ]);
        $data['cashbook_id'] = $id;
        $movement = $this->cash->recordMovement((string) $request->attributes->get('tenant_id'), $data);

        return response()->json(['data' => $movement], 201);
    }

    public function fiscalise(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'environment' => 'nullable|string|max:20',
        ]);
        $submission = $this->fiscal->fiscalise(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['environment'] ?? 'sandbox',
        );

        return response()->json(['data' => $submission], 201);
    }
}
