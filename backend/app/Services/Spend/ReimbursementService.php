<?php

declare(strict_types=1);

namespace App\Services\Spend;

use App\Models\ExpenseReport;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\EventStore;
use App\Services\Financial\DocumentNumberService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Record-only reimbursements: the actual payout happens outside the system
 * (bank transfer, payroll run); mark-paid records the reference and flips
 * the source report to reimbursed.
 */
class ReimbursementService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
        private readonly NotificationService $notifications,
    ) {}

    public function createFromReport(ExpenseReport $report): Reimbursement
    {
        return DB::transaction(function () use ($report) {
            $reimbursement = Reimbursement::create([
                'tenant_id' => $report->tenant_id,
                'expense_report_id' => $report->id,
                'reimbursement_number' => $this->documentNumbers->next($report->tenant_id, 'reimbursement', 'REI'),
                'user_id' => $report->user_id,
                'amount' => $report->total_amount,
                'currency' => $report->currency,
                'status' => 'pending',
            ]);

            $this->eventStore->append(
                $report->tenant_id,
                'reimbursement',
                $reimbursement->id,
                'reimbursement.created',
                [
                    'reimbursement_number' => $reimbursement->reimbursement_number,
                    'expense_report_id' => $report->id,
                    'report_number' => $report->report_number,
                    'amount' => (string) $reimbursement->amount,
                    'currency' => $reimbursement->currency,
                ]
            );

            return $reimbursement;
        });
    }

    public function markPaid(string $id, string $tenantId, ?string $reference = null, ?string $method = null): Reimbursement
    {
        return DB::transaction(function () use ($id, $tenantId, $reference, $method) {
            $reimbursement = Reimbursement::forTenant($tenantId)->findOrFail($id);

            if ($reimbursement->status !== 'pending') {
                throw new \LogicException("Only pending reimbursements can be marked paid. Current status: {$reimbursement->status}");
            }

            $reimbursement->update([
                'status' => 'paid',
                'paid_at' => now(),
                'reference' => $reference,
                'method' => $method,
            ]);

            $report = ExpenseReport::forTenant($tenantId)->find($reimbursement->expense_report_id);
            if ($report && $report->status === 'approved') {
                $report->update(['status' => 'reimbursed', 'reimbursed_at' => now()]);
            }

            $this->eventStore->append(
                $tenantId,
                'reimbursement',
                $reimbursement->id,
                'reimbursement.paid',
                [
                    'reimbursement_number' => $reimbursement->reimbursement_number,
                    'amount' => (string) $reimbursement->amount,
                    'reference' => $reference,
                    'method' => $method,
                ]
            );

            $this->notifyPayee($reimbursement, 'reimbursement_paid', [
                'title' => 'Reimbursement paid',
                'body' => "{$reimbursement->reimbursement_number} ({$reimbursement->currency} {$reimbursement->amount}) has been paid.",
                'url' => '/spend/reimbursements',
            ]);

            return $reimbursement->fresh();
        });
    }

    public function cancel(string $id, string $tenantId, ?string $reason = null): Reimbursement
    {
        return DB::transaction(function () use ($id, $tenantId, $reason) {
            $reimbursement = Reimbursement::forTenant($tenantId)->findOrFail($id);

            if ($reimbursement->status !== 'pending') {
                throw new \LogicException("Only pending reimbursements can be cancelled. Current status: {$reimbursement->status}");
            }

            $reimbursement->update([
                'status' => 'cancelled',
                'notes' => $reason,
            ]);

            $this->eventStore->append(
                $tenantId,
                'reimbursement',
                $reimbursement->id,
                'reimbursement.cancelled',
                [
                    'reimbursement_number' => $reimbursement->reimbursement_number,
                    'reason' => $reason,
                ]
            );

            return $reimbursement->fresh();
        });
    }

    private function notifyPayee(Reimbursement $reimbursement, string $eventType, array $payload): void
    {
        try {
            $user = User::find($reimbursement->user_id);
            if ($user) {
                $this->notifications->notify($user, $eventType, $payload);
            }
        } catch (\Throwable $e) {
            Log::warning('Reimbursement notification failed', ['reimbursement' => $reimbursement->id, 'error' => $e->getMessage()]);
        }
    }
}
