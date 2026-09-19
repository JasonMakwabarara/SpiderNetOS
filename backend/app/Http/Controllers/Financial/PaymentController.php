<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\Financial\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $payments = Payment::forTenant($tenant->id)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json(['data' => $payments]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $payment = Payment::forTenant($tenant->id)->findOrFail($id);

        return response()->json(['data' => $payment]);
    }

    public function recordPayment(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string',
            'currency' => 'sometimes|string|size:3',
            'invoice_id' => 'sometimes|uuid|exists:invoices,id',
            'description' => 'sometimes|string',
            'provider' => 'sometimes|string',
            'provider_reference' => 'sometimes|string',
            'idempotency_key' => 'sometimes|string|unique:payments,idempotency_key',
        ]);

        $payment = $this->paymentService->recordPayment(
            $tenant->id,
            $validated['amount'],
            $validated['method'],
            $validated['currency'] ?? 'USD',
            $validated['invoice_id'] ?? null,
            $validated['description'] ?? null,
            $validated['provider'] ?? null,
            $validated['provider_reference'] ?? null,
            $validated['idempotency_key'] ?? null,
        );

        return response()->json(['data' => $payment], 201);
    }

    public function initiatePayment(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string',
            'currency' => 'sometimes|string|size:3',
            'destination_account_id' => 'sometimes|string',
            'counterparty_name' => 'sometimes|string',
            'counterparty_email' => 'sometimes|email',
            'description' => 'sometimes|string',
        ]);

        $transaction = $this->paymentService->initiatePayment(
            $tenant->id,
            $validated['amount'],
            $validated['method'],
            $validated['currency'] ?? 'USD',
            $validated['destination_account_id'] ?? null,
            $validated['counterparty_name'] ?? null,
            $validated['counterparty_email'] ?? null,
            $validated['description'] ?? null,
        );

        return response()->json(['data' => $transaction], 201);
    }

    public function transactions(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $transactions = Transaction::forTenant($tenant->id)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json(['data' => $transactions]);
    }

    public function summary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $summary = $this->paymentService->getPaymentSummary($tenant->id);

        return response()->json(['data' => $summary]);
    }
}
