<?php

declare(strict_types=1);

namespace App\Services\Spend;

use App\Jobs\ExtractSpendDocumentJob;
use App\Models\Bill;
use App\Models\BillLineItem;
use App\Models\PaymentInstruction;
use App\Models\SpendDocument;
use App\Services\ApprovalEngine;
use App\Services\EventStore;
use App\Services\Financial\DocumentNumberService;
use App\Services\Financial\PaymentService;
use App\Services\Notifications\NotificationService;
use App\Services\Spend\Rails\PaymentRailManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bill (accounts payable) lifecycle:
 *   draft -> submit -> (policy match? awaiting_approval : approved)
 *         -> approved -> scheduled -> paid
 *         -> void (rejection or manual)
 *
 * Record-only V1: schedulePayment records a PaymentInstruction and the sweep
 * NOTIFIES admins when it comes due — it never moves money. markPaid records
 * the external bank reference after the fact.
 */
class BillService
{
    private const AGING_BUCKETS = ['current', '1_30', '31_60', '61_90', '90_plus'];

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly ApprovalEngine $approvalEngine,
        private readonly DocumentNumberService $documentNumbers,
        private readonly PaymentService $payments,
        private readonly PaymentRailManager $railManager,
        private readonly NotificationService $notifications,
    ) {}

    // ------------------------------------------------------------------ //
    //  Draft lifecycle
    // ------------------------------------------------------------------ //

    public function createBill(string $tenantId, array $data, array $lines = []): Bill
    {
        return DB::transaction(function () use ($tenantId, $data, $lines) {
            $bill = Bill::create([
                'tenant_id' => $tenantId,
                'vendor_id' => $data['vendor_id'] ?? null,
                'bill_number' => $this->documentNumbers->next($tenantId, 'bill', 'BILL'),
                'vendor_invoice_ref' => $data['vendor_invoice_ref'] ?? null,
                'status' => 'draft',
                'currency' => $data['currency'] ?? 'USD',
                'issue_date' => $data['issue_date'] ?? null,
                'due_date' => $data['due_date'],
                'source' => $data['source'] ?? 'manual',
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $this->replaceLines($bill, $lines);

            $bill = $bill->fresh('lineItems');

            $this->eventStore->append(
                $tenantId,
                'bill',
                $bill->id,
                'bill.created',
                [
                    'bill_number' => $bill->bill_number,
                    'vendor_id' => $bill->vendor_id,
                    'source' => $bill->source,
                    'line_count' => count($lines),
                    'total_amount' => (string) $bill->total_amount,
                    'currency' => $bill->currency,
                ]
            );

            return $bill;
        });
    }

    /**
     * Upload path: create a draft bill (source=upload) with a placeholder
     * due date, attach the file as a SpendDocument (kind=bill) and queue the
     * AI extraction pipeline after commit.
     */
    public function ingestUploadedBill(string $tenantId, UploadedFile $file, string $uploadedBy): Bill
    {
        $bill = DB::transaction(function () use ($tenantId, $file, $uploadedBy) {
            $bill = Bill::create([
                'tenant_id' => $tenantId,
                'bill_number' => $this->documentNumbers->next($tenantId, 'bill', 'BILL'),
                'status' => 'draft',
                'currency' => 'USD',
                'due_date' => now()->addDays(30)->toDateString(),
                'source' => 'upload',
                'metadata' => ['pending_extraction' => true],
            ]);

            $extension = $file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin');
            $filename = (string) Str::uuid().'.'.strtolower($extension);
            $path = Storage::disk('local')->putFileAs("spend/{$tenantId}/bills", $file, $filename);

            $document = $bill->documents()->create([
                'tenant_id' => $tenantId,
                'uploaded_by' => $uploadedBy,
                'kind' => 'bill',
                'disk' => 'local',
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'status' => 'uploaded',
            ]);

            $this->eventStore->append(
                $tenantId,
                'bill',
                $bill->id,
                'bill.created',
                [
                    'bill_number' => $bill->bill_number,
                    'source' => 'upload',
                    'document_id' => $document->id,
                    'original_filename' => $document->original_filename,
                    'sha256' => $document->sha256,
                ]
            );

            ExtractSpendDocumentJob::dispatch($document->id)->afterCommit();

            return $bill;
        });

        return $bill->fresh(['lineItems', 'documents']);
    }

    public function updateBill(string $billId, string $tenantId, array $data, ?array $lines = null): Bill
    {
        return DB::transaction(function () use ($billId, $tenantId, $data, $lines) {
            $bill = $this->draftOrFail($billId, $tenantId);

            $bill->update(array_intersect_key($data, array_flip([
                'vendor_id', 'vendor_invoice_ref', 'currency', 'issue_date',
                'due_date', 'notes', 'terms', 'metadata',
            ])));

            if ($lines !== null) {
                $bill->lineItems()->delete();
                $this->replaceLines($bill, $lines);
            }

            return $bill->fresh('lineItems');
        });
    }

    // ------------------------------------------------------------------ //
    //  Submission / approval
    // ------------------------------------------------------------------ //

    public function submitForApproval(string $billId, string $tenantId, string $requesterId): Bill
    {
        return DB::transaction(function () use ($billId, $tenantId, $requesterId) {
            $bill = $this->draftOrFail($billId, $tenantId);

            $total = (string) $bill->total_amount;

            $this->eventStore->append(
                $tenantId,
                'bill',
                $bill->id,
                'bill.submitted',
                [
                    'bill_number' => $bill->bill_number,
                    'total_amount' => $total,
                    'currency' => $bill->currency,
                ]
            );

            $policy = $this->approvalEngine->matchPolicy($tenantId, 'bill', 'submit', [
                'amount' => $total,
                'currency' => $bill->currency,
            ]);

            if ($policy === null) {
                // No approval chain configured for bills — straight to approved.
                $this->markApproved($bill);

                return $bill->fresh('lineItems');
            }

            $approval = $this->approvalEngine->createChainedApproval(
                $tenantId,
                $requesterId,
                'spend',
                'bill',
                $bill->id,
                "Bill {$bill->bill_number} ({$bill->currency} {$total})",
                [
                    'action' => 'submit',
                    'attributes' => ['amount' => $total, 'currency' => $bill->currency],
                ],
                $policy,
            );

            if (($approval['status'] ?? null) === 'pending') {
                $bill->update([
                    'status' => 'awaiting_approval',
                    'approval_id' => $approval['id'],
                ]);
            }

            return $bill->fresh('lineItems');
        });
    }

    /**
     * Resource hook fired by ApprovalEngine when the approval chain for a
     * bill resolves. Runs inside the engine's transaction.
     */
    public function onApprovalResolved(string $tenantId, string $billId, bool $granted): void
    {
        $bill = Bill::forTenant($tenantId)->find($billId);

        if (!$bill || $bill->status !== 'awaiting_approval') {
            return;
        }

        if ($granted) {
            $this->markApproved($bill);
            $this->notifyAdmins($tenantId, 'bill_approved', [
                'title' => 'Bill approved',
                'body' => "{$bill->bill_number} was approved for {$bill->currency} {$bill->total_amount}.",
                'url' => '/spend/bills/'.$bill->id,
            ]);

            return;
        }

        $reason = DB::table('approvals')
            ->where('tenant_id', $tenantId)
            ->where('resource_type', 'bill')
            ->where('resource_id', $billId)
            ->orderByDesc('updated_at')
            ->value('response');

        $metadata = $bill->metadata ?? [];
        $metadata['rejection_reason'] = $reason;

        $bill->update([
            'status' => 'void',
            'void_at' => now()->toDateString(),
            'metadata' => $metadata,
        ]);

        $this->eventStore->append(
            $tenantId,
            'bill',
            $bill->id,
            'bill.rejected',
            ['bill_number' => $bill->bill_number, 'reason' => $reason]
        );

        $this->notifyAdmins($tenantId, 'bill_rejected', [
            'title' => 'Bill rejected',
            'body' => $reason !== null && $reason !== ''
                ? "{$bill->bill_number} was rejected: {$reason}"
                : "{$bill->bill_number} was rejected.",
            'url' => '/spend/bills/'.$bill->id,
        ]);
    }

    // ------------------------------------------------------------------ //
    //  Payment
    // ------------------------------------------------------------------ //

    public function schedulePayment(string $billId, string $tenantId, string $date): Bill
    {
        return DB::transaction(function () use ($billId, $tenantId, $date) {
            $bill = Bill::forTenant($tenantId)->findOrFail($billId);

            if ($bill->status !== 'approved') {
                throw new \LogicException("Only approved bills can be scheduled. Current status: {$bill->status}");
            }

            $rail = $this->railManager->railFor($tenantId);

            $instruction = PaymentInstruction::firstOrNew([
                'tenant_id' => $tenantId,
                'idempotency_key' => "bill-{$bill->id}",
            ]);
            $instruction->fill([
                'bill_id' => $bill->id,
                'rail' => $rail->name(),
                'status' => 'scheduled',
                'amount' => (string) $bill->total_amount,
                'currency' => $bill->currency,
                'scheduled_for' => $date,
            ]);
            $instruction->save();

            $bill->update(['status' => 'scheduled', 'scheduled_for' => $date]);

            $this->eventStore->append(
                $tenantId,
                'bill',
                $bill->id,
                'bill.payment_scheduled',
                [
                    'bill_number' => $bill->bill_number,
                    'scheduled_for' => $date,
                    'rail' => $rail->name(),
                    'instruction_id' => $instruction->id,
                    'amount' => (string) $bill->total_amount,
                ]
            );

            return $bill->fresh('lineItems');
        });
    }

    public function markPaid(
        string $billId,
        string $tenantId,
        ?string $bankReference = null,
        string $method = 'bank_transfer',
    ): Bill {
        return DB::transaction(function () use ($billId, $tenantId, $bankReference, $method) {
            $bill = Bill::forTenant($tenantId)->findOrFail($billId);

            if (!in_array($bill->status, ['approved', 'scheduled'], true)) {
                throw new \LogicException("Only approved or scheduled bills can be marked paid. Current status: {$bill->status}");
            }

            $payment = $this->payments->recordOutgoingPayment(
                $tenantId,
                (string) $bill->total_amount,
                $method,
                $bill->currency,
                $bill->id,
                $bankReference,
                "bill-paid-{$bill->id}",
            );

            $bill->update([
                'status' => 'paid',
                'paid_at' => now()->toDateString(),
                'payment_id' => $payment->id,
            ]);

            $instruction = PaymentInstruction::forTenant($tenantId)
                ->where('bill_id', $bill->id)
                ->whereNotIn('status', ['settled', 'cancelled'])
                ->first();

            if ($instruction) {
                $instruction->update([
                    'status' => 'settled',
                    'external_reference' => $bankReference ?? $instruction->external_reference,
                ]);
            }

            $this->eventStore->append(
                $tenantId,
                'bill',
                $bill->id,
                'bill.payment_recorded',
                [
                    'bill_number' => $bill->bill_number,
                    'payment_id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'method' => $method,
                    'bank_reference' => $bankReference,
                    'amount' => (string) $bill->total_amount,
                ]
            );

            $this->eventStore->append(
                $tenantId,
                'bill',
                $bill->id,
                'bill.paid',
                [
                    'bill_number' => $bill->bill_number,
                    'amount' => (string) $bill->total_amount,
                    'currency' => $bill->currency,
                ]
            );

            $this->notifyAdmins($tenantId, 'bill_paid', [
                'title' => 'Bill paid',
                'body' => "{$bill->bill_number} ({$bill->currency} {$bill->total_amount}) was marked paid.",
                'url' => '/spend/bills/'.$bill->id,
            ]);

            return $bill->fresh('lineItems');
        });
    }

    public function voidBill(string $billId, string $tenantId, ?string $reason = null): Bill
    {
        return DB::transaction(function () use ($billId, $tenantId, $reason) {
            $bill = Bill::forTenant($tenantId)->findOrFail($billId);

            if (in_array($bill->status, ['paid', 'void'], true)) {
                throw new \LogicException("A {$bill->status} bill cannot be voided.");
            }

            $metadata = $bill->metadata ?? [];
            if ($reason !== null && $reason !== '') {
                $metadata['void_reason'] = $reason;
            }

            $bill->update([
                'status' => 'void',
                'void_at' => now()->toDateString(),
                'metadata' => $metadata,
            ]);

            PaymentInstruction::forTenant($tenantId)
                ->where('bill_id', $bill->id)
                ->whereIn('status', ['pending', 'scheduled'])
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            $this->eventStore->append(
                $tenantId,
                'bill',
                $bill->id,
                'bill.voided',
                ['bill_number' => $bill->bill_number, 'reason' => $reason]
            );

            return $bill->fresh('lineItems');
        });
    }

    // ------------------------------------------------------------------ //
    //  Sweep / queries
    // ------------------------------------------------------------------ //

    /**
     * Record-only V1: bills whose scheduled payment date has arrived are
     * NOT auto-paid — tenant admins get a bill_payment_due notification
     * (once per day per bill) prompting the manual transfer + mark-paid.
     *
     * @return int number of bills notified on this run
     */
    public function sweepScheduledPayments(?string $asOf = null): int
    {
        $asOf = $asOf ?? now()->toDateString();
        $count = 0;

        $due = Bill::where('status', 'scheduled')
            ->whereDate('scheduled_for', '<=', $asOf)
            ->orderBy('scheduled_for')
            ->get();

        foreach ($due as $bill) {
            $metadata = $bill->metadata ?? [];
            if (($metadata['due_notified_on'] ?? null) === $asOf) {
                continue; // Already notified today.
            }

            $this->eventStore->append(
                $bill->tenant_id,
                'bill',
                $bill->id,
                'bill.payment_due',
                [
                    'bill_number' => $bill->bill_number,
                    'scheduled_for' => optional($bill->scheduled_for)->toDateString(),
                    'amount' => (string) $bill->total_amount,
                    'currency' => $bill->currency,
                ]
            );

            $this->notifyAdmins($bill->tenant_id, 'bill_payment_due', [
                'title' => 'Bill payment due',
                'body' => "{$bill->bill_number} ({$bill->currency} {$bill->total_amount}) is scheduled for payment — record the transfer and mark it paid.",
                'url' => '/spend/bills/'.$bill->id,
            ]);

            $metadata['due_notified_on'] = $asOf;
            $bill->update(['metadata' => $metadata]);

            $count++;
        }

        return $count;
    }

    /** Unpaid bills due within $days (including already-overdue ones). */
    public function getDueSoon(string $tenantId, int $days = 7): \Illuminate\Support\Collection
    {
        return Bill::forTenant($tenantId)
            ->unpaid()
            ->whereDate('due_date', '<=', now()->addDays($days)->toDateString())
            ->orderBy('due_date')
            ->with('vendor')
            ->get();
    }

    /** @return array<string, array{count: int, total: float}> */
    public function getSummary(string $tenantId): array
    {
        $statuses = ['draft', 'awaiting_approval', 'approved', 'scheduled', 'paid', 'void'];

        $result = array_fill_keys($statuses, ['count' => 0, 'total' => 0]);

        $rows = Bill::forTenant($tenantId)
            ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $result[$row->status] = ['count' => (int) $row->count, 'total' => (float) $row->total];
        }

        return $result;
    }

    /**
     * AP aging: unpaid bills bucketed by how overdue they are (due_date vs
     * today). current = not yet due.
     *
     * @return array<string, array{count: int, total: float}>
     */
    public function getAging(string $tenantId): array
    {
        $buckets = array_fill_keys(self::AGING_BUCKETS, ['count' => 0, 'total' => 0.0]);

        $today = Carbon::today();

        Bill::forTenant($tenantId)
            ->unpaid()
            ->get(['id', 'due_date', 'total_amount'])
            ->each(function (Bill $bill) use (&$buckets, $today) {
                $daysOverdue = $bill->due_date->lt($today)
                    ? (int) $bill->due_date->diffInDays($today)
                    : 0;

                $bucket = match (true) {
                    $daysOverdue <= 0 => 'current',
                    $daysOverdue <= 30 => '1_30',
                    $daysOverdue <= 60 => '31_60',
                    $daysOverdue <= 90 => '61_90',
                    default => '90_plus',
                };

                $buckets[$bucket]['count']++;
                $buckets[$bucket]['total'] += (float) $bill->total_amount;
            });

        return $buckets;
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    private function markApproved(Bill $bill): void
    {
        $bill->update(['status' => 'approved']);

        $this->eventStore->append(
            $bill->tenant_id,
            'bill',
            $bill->id,
            'bill.approved',
            [
                'bill_number' => $bill->bill_number,
                'total_amount' => (string) $bill->total_amount,
            ]
        );
    }

    /** Create line rows and roll subtotal/tax/total up onto the bill. */
    private function replaceLines(Bill $bill, array $lines): void
    {
        $subtotal = '0';
        $tax = '0';

        foreach ($lines as $line) {
            $quantity = (string) ($line['quantity'] ?? '1');
            $unitPrice = (string) $line['unit_price'];
            $taxRate = (string) ($line['tax_rate'] ?? '0');

            $lineTotal = bcmul($quantity, $unitPrice, 4);
            $lineTax = bcmul($lineTotal, bcdiv($taxRate, '100', 6), 4);

            BillLineItem::create([
                'bill_id' => $bill->id,
                'tenant_id' => $bill->tenant_id,
                'description' => $line['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'total' => $lineTotal,
                'category_id' => $line['category_id'] ?? null,
                'gl_account_id' => $line['gl_account_id'] ?? null,
                'metadata' => $line['metadata'] ?? null,
            ]);

            $subtotal = bcadd($subtotal, $lineTotal, 4);
            $tax = bcadd($tax, $lineTax, 4);
        }

        $bill->update([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => bcadd($subtotal, $tax, 4),
        ]);
    }

    private function draftOrFail(string $billId, string $tenantId): Bill
    {
        $bill = Bill::forTenant($tenantId)->findOrFail($billId);

        if (!$bill->isDraft()) {
            throw new \LogicException("Bill must be in draft status. Current status: {$bill->status}");
        }

        return $bill;
    }

    private function notifyAdmins(string $tenantId, string $eventType, array $payload): void
    {
        try {
            $this->notifications->notifyTenantRole($tenantId, ['admin', 'super_admin'], $eventType, $payload);
        } catch (\Throwable $e) {
            Log::warning('Bill notification failed', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
        }
    }
}
