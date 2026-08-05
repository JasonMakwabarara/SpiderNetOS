<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\Invoice;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    public function recordPayment(
        string $tenantId,
        string $amount,
        string $method,
        string $currency = 'USD',
        ?string $invoiceId = null,
        ?string $description = null,
        ?string $provider = null,
        ?string $providerReference = null,
        ?string $idempotencyKey = null,
    ): Payment {
        return DB::transaction(function () use (
            $tenantId, $amount, $method, $currency, $invoiceId,
            $description, $provider, $providerReference, $idempotencyKey
        ) {
            if ($idempotencyKey) {
                $existing = Payment::where('tenant_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $paymentNumber = $this->generatePaymentNumber($tenantId);

            $transaction = null;
            if ($invoiceId) {
                $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($invoiceId);
                $invoice->markAsPaid();
            }

            $payment = Payment::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoiceId,
                'payment_number' => $paymentNumber,
                'type' => 'received',
                'amount' => $amount,
                'currency' => $currency,
                'method' => $method,
                'status' => 'completed',
                'provider' => $provider,
                'provider_reference' => $providerReference,
                'idempotency_key' => $idempotencyKey,
                'notes' => $description,
                'paid_at' => now(),
            ]);

            $this->eventStore->append(
                $tenantId,
                'payment',
                $payment->id,
                'payment.received',
                [
                    'payment_number' => $paymentNumber,
                    'amount' => $amount,
                    'method' => $method,
                    'invoice_id' => $invoiceId,
                    'provider' => $provider,
                ]
            );

            return $payment;
        });
    }

    public function initiatePayment(
        string $tenantId,
        string $amount,
        string $method,
        string $currency = 'USD',
        ?string $destinationAccountId = null,
        ?string $counterpartyName = null,
        ?string $counterpartyEmail = null,
        ?string $description = null,
    ): Transaction {
        return DB::transaction(function () use (
            $tenantId, $amount, $method, $currency,
            $destinationAccountId, $counterpartyName, $counterpartyEmail, $description
        ) {
            $transactionNumber = $this->generateTransactionNumber($tenantId);

            $transaction = Transaction::create([
                'tenant_id' => $tenantId,
                'transaction_number' => $transactionNumber,
                'type' => 'outgoing_payment',
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'payment_method' => $method,
                'destination_account_id' => $destinationAccountId,
                'counterparty_name' => $counterpartyName,
                'counterparty_email' => $counterpartyEmail,
                'description' => $description,
                'initiated_at' => now(),
            ]);

            $this->eventStore->append(
                $tenantId,
                'payment',
                $transaction->id,
                'payment.initiated',
                [
                    'transaction_number' => $transactionNumber,
                    'amount' => $amount,
                    'method' => $method,
                ]
            );

            return $transaction;
        });
    }

    public function completePayment(string $transactionId, string $tenantId, ?string $providerReference = null): Transaction
    {
        $transaction = Transaction::where('tenant_id', $tenantId)->findOrFail($transactionId);

        $transaction->update([
            'status' => 'completed',
            'completed_at' => now(),
            'provider_reference' => $providerReference,
        ]);

        $this->eventStore->append(
            $tenantId,
            'payment',
            $transaction->id,
            'payment.completed',
            [
                'transaction_number' => $transaction->transaction_number,
                'amount' => $transaction->amount,
            ]
        );

        return $transaction;
    }

    public function failPayment(string $transactionId, string $tenantId, string $reason): Transaction
    {
        $transaction = Transaction::where('tenant_id', $tenantId)->findOrFail($transactionId);

        $transaction->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);

        $this->eventStore->append(
            $tenantId,
            'payment',
            $transaction->id,
            'payment.failed',
            [
                'transaction_number' => $transaction->transaction_number,
                'reason' => $reason,
            ]
        );

        return $transaction;
    }

    public function getPaymentsByMethod(string $tenantId, string $method, ?int $limit = 50): array
    {
        return Payment::forTenant($tenantId)
            ->where('method', $method)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function getPaymentSummary(string $tenantId): array
    {
        $totalReceived = Payment::forTenant($tenantId)
            ->where('status', 'completed')
            ->where('type', 'received')
            ->sum('amount');

        $totalSent = Payment::forTenant($tenantId)
            ->where('status', 'completed')
            ->where('type', 'sent')
            ->sum('amount');

        $pendingAmount = Payment::forTenant($tenantId)
            ->where('status', 'pending')
            ->sum('amount');

        return [
            'total_received' => (float) $totalReceived,
            'total_sent' => (float) $totalSent,
            'pending' => (float) $pendingAmount,
            'net' => (float) $totalReceived - (float) $totalSent,
        ];
    }

    private function generatePaymentNumber(string $tenantId): string
    {
        return $this->documentNumbers->next($tenantId, 'payment', 'PAY');
    }

    private function generateTransactionNumber(string $tenantId): string
    {
        return $this->documentNumbers->next($tenantId, 'transaction', 'TXN');
    }
}
