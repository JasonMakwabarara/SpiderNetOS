<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Customer;
use App\Services\Financial\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        
        $query = Invoice::forTenant($tenant->id)->with('lineItems');

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        $invoices = $query->orderByDesc('created_at')->paginate($request->get('per_page', 20));

        return response()->json(['data' => $invoices]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $invoice = Invoice::forTenant($tenant->id)->with('lineItems')->findOrFail($id);

        return response()->json(['data' => $invoice]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'customer_name' => 'required|string',
            'customer_email' => 'sometimes|email',
            'customer_id' => 'sometimes|uuid|exists:customers,id',
            'due_date' => 'required|date',
            'issue_date' => 'sometimes|date',
            'currency' => 'sometimes|string|size:3',
            'status' => 'sometimes|in:draft,sent',
            'notes' => 'sometimes|string',
            'terms' => 'sometimes|string',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'discount_amount' => 'sometimes|numeric|min:0',
            'line_items' => 'required|array|min:1',
            'line_items.*.description' => 'required|string',
            'line_items.*.quantity' => 'sometimes|numeric|min:0.01',
            'line_items.*.unit_price' => 'required|numeric|min:0',
            'line_items.*.tax_rate' => 'sometimes|numeric|min:0|max:100',
            'line_items.*.sku' => 'sometimes|string',
        ]);

        $invoice = $this->invoiceService->createInvoice(
            $tenant->id,
            $validated,
            $validated['line_items'],
        );

        return response()->json(['data' => $invoice], 201);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $invoice = $this->invoiceService->sendInvoice($id, $tenant->id);

        return response()->json(['data' => $invoice]);
    }

    public function markPaid(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $invoice = $this->invoiceService->markInvoicePaid($id, $tenant->id);

        return response()->json(['data' => $invoice]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $invoice = $this->invoiceService->cancelInvoice($id, $tenant->id);

        return response()->json(['data' => $invoice]);
    }

    public function overdue(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $invoices = $this->invoiceService->getOverdueInvoices($tenant->id);

        return response()->json(['data' => $invoices]);
    }

    public function summary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $summary = $this->invoiceService->getInvoiceSummary($tenant->id);

        return response()->json(['data' => $summary]);
    }

    public function customers(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $customers = Customer::forTenant($tenant->id)
            ->orderBy('name')
            ->paginate($request->get('per_page', 20));

        return response()->json(['data' => $customers]);
    }

    public function createCustomer(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'sometimes|email',
            'phone' => 'sometimes|string',
            'company' => 'sometimes|string',
            'tax_id' => 'sometimes|string',
            'address' => 'sometimes|array',
            'credit_limit' => 'sometimes|numeric|min:0',
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'company' => $validated['company'] ?? null,
            'tax_id' => $validated['tax_id'] ?? null,
            'address' => $validated['address'] ?? null,
            'credit_limit' => $validated['credit_limit'] ?? null,
        ]);

        return response()->json(['data' => $customer], 201);
    }
}
