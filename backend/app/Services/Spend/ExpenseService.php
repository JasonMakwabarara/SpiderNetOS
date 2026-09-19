<?php

declare(strict_types=1);

namespace App\Services\Spend;

use App\Models\ExpenseItem;
use App\Models\ExpenseReport;
use App\Models\SpendDocument;
use App\Models\User;
use App\Services\ApprovalEngine;
use App\Services\EventStore;
use App\Services\Financial\DocumentNumberService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Expense report lifecycle:
 *   draft -> submit -> (policy match? awaiting_approval : auto-approved)
 *         -> approved -> reimbursement (record-only) -> reimbursed
 *         -> rejected | void
 *
 * Policy evaluation is advisory (flags, not blocks); approval routing is
 * delegated to ApprovalEngine, which calls back onApprovalResolved().
 */
class ExpenseService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly ApprovalEngine $approvalEngine,
        private readonly ExpensePolicyService $policyService,
        private readonly DocumentNumberService $documentNumbers,
        private readonly NotificationService $notifications,
    ) {}

    // ------------------------------------------------------------------ //
    //  Draft lifecycle
    // ------------------------------------------------------------------ //

    public function createReport(string $tenantId, string $userId, array $data, array $items = []): ExpenseReport
    {
        return DB::transaction(function () use ($tenantId, $userId, $data, $items) {
            $currency = $data['currency'] ?? 'USD';

            $report = ExpenseReport::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'report_number' => $this->documentNumbers->next($tenantId, 'expense_report', 'EXP'),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'currency' => $currency,
                'total_amount' => 0,
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach ($items as $item) {
                ExpenseItem::create([
                    'expense_report_id' => $report->id,
                    'tenant_id' => $tenantId,
                    'category_id' => $item['category_id'] ?? null,
                    'merchant' => $item['merchant'] ?? null,
                    'description' => $item['description'],
                    'expense_date' => $item['expense_date'],
                    'amount' => $item['amount'],
                    'currency' => $item['currency'] ?? $currency,
                    'gl_account_id' => $item['gl_account_id'] ?? null,
                    'metadata' => $item['metadata'] ?? null,
                ]);
            }

            $this->recomputeTotal($report);

            $this->eventStore->append(
                $tenantId,
                'expense_report',
                $report->id,
                'expense_report.created',
                [
                    'report_number' => $report->report_number,
                    'title' => $report->title,
                    'item_count' => count($items),
                    'total_amount' => (string) $report->fresh()->total_amount,
                ]
            );

            return $report->fresh('items');
        });
    }

    public function updateReport(string $reportId, string $tenantId, array $data): ExpenseReport
    {
        return DB::transaction(function () use ($reportId, $tenantId, $data) {
            $report = $this->draftOrFail($reportId, $tenantId);

            $report->update(array_intersect_key($data, array_flip([
                'title', 'description', 'currency', 'metadata',
            ])));

            return $report->fresh('items');
        });
    }

    public function addItem(string $reportId, string $tenantId, array $data): ExpenseItem
    {
        return DB::transaction(function () use ($reportId, $tenantId, $data) {
            $report = $this->draftOrFail($reportId, $tenantId);

            $item = ExpenseItem::create([
                'expense_report_id' => $report->id,
                'tenant_id' => $tenantId,
                'category_id' => $data['category_id'] ?? null,
                'merchant' => $data['merchant'] ?? null,
                'description' => $data['description'],
                'expense_date' => $data['expense_date'],
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? $report->currency,
                'gl_account_id' => $data['gl_account_id'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $this->recomputeTotal($report);

            return $item;
        });
    }

    public function removeItem(string $reportId, string $itemId, string $tenantId): ExpenseReport
    {
        return DB::transaction(function () use ($reportId, $itemId, $tenantId) {
            $report = $this->draftOrFail($reportId, $tenantId);

            $item = ExpenseItem::where('expense_report_id', $report->id)
                ->where('tenant_id', $tenantId)
                ->findOrFail($itemId);

            foreach ($item->receipts as $doc) {
                Storage::disk($doc->disk)->delete($doc->path);
                $doc->delete();
            }

            $item->delete();
            $this->recomputeTotal($report);

            return $report->fresh('items');
        });
    }

    // ------------------------------------------------------------------ //
    //  Receipts
    // ------------------------------------------------------------------ //

    public function attachReceipt(
        string $reportId,
        string $itemId,
        string $tenantId,
        UploadedFile $file,
        string $uploadedBy,
    ): SpendDocument {
        return DB::transaction(function () use ($reportId, $itemId, $tenantId, $file, $uploadedBy) {
            $report = ExpenseReport::forTenant($tenantId)->findOrFail($reportId);

            if (in_array($report->status, ['approved', 'rejected', 'reimbursed', 'void'], true)) {
                throw new \LogicException("Receipts cannot be attached to a {$report->status} report.");
            }

            $item = ExpenseItem::where('expense_report_id', $report->id)
                ->where('tenant_id', $tenantId)
                ->findOrFail($itemId);

            $extension = $file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin');
            $filename = (string) Str::uuid().'.'.strtolower($extension);
            $path = Storage::disk('local')->putFileAs("spend/{$tenantId}/receipts", $file, $filename);

            $document = $item->receipts()->create([
                'tenant_id' => $tenantId,
                'uploaded_by' => $uploadedBy,
                'kind' => 'receipt',
                'disk' => 'local',
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'status' => 'uploaded',
            ]);

            $item->update(['has_receipt' => true]);

            $this->eventStore->append(
                $tenantId,
                'expense_report',
                $report->id,
                'expense_report.receipt_attached',
                [
                    'report_number' => $report->report_number,
                    'item_id' => $item->id,
                    'document_id' => $document->id,
                    'original_filename' => $document->original_filename,
                    'sha256' => $document->sha256,
                ]
            );

            return $document;
        });
    }

    public function removeReceipt(string $reportId, string $documentId, string $tenantId): ExpenseReport
    {
        return DB::transaction(function () use ($reportId, $documentId, $tenantId) {
            $report = ExpenseReport::forTenant($tenantId)->findOrFail($reportId);

            $document = SpendDocument::forTenant($tenantId)
                ->where('attachable_type', ExpenseItem::class)
                ->whereIn('attachable_id', $report->items()->pluck('id'))
                ->findOrFail($documentId);

            $itemId = $document->attachable_id;

            Storage::disk($document->disk)->delete($document->path);
            $document->delete();

            $remaining = SpendDocument::forTenant($tenantId)
                ->where('attachable_type', ExpenseItem::class)
                ->where('attachable_id', $itemId)
                ->exists();

            ExpenseItem::where('id', $itemId)->update(['has_receipt' => $remaining]);

            $this->eventStore->append(
                $tenantId,
                'expense_report',
                $report->id,
                'expense_report.receipt_removed',
                ['report_number' => $report->report_number, 'item_id' => $itemId, 'document_id' => $documentId]
            );

            return $report->fresh('items');
        });
    }

    // ------------------------------------------------------------------ //
    //  Submission / approval
    // ------------------------------------------------------------------ //

    public function submitReport(string $reportId, string $tenantId): ExpenseReport
    {
        return DB::transaction(function () use ($reportId, $tenantId) {
            $report = $this->draftOrFail($reportId, $tenantId);

            $violations = $this->policyService->evaluate($report);
            $report->refresh();
            $total = (string) $report->total_amount;

            $report->update(['status' => 'submitted', 'submitted_at' => now()]);

            $this->eventStore->append(
                $tenantId,
                'expense_report',
                $report->id,
                'expense_report.submitted',
                [
                    'report_number' => $report->report_number,
                    'total_amount' => $total,
                    'violation_count' => $report->policy_violation_count,
                    'violations' => $violations,
                ]
            );

            $policy = $this->approvalEngine->matchPolicy($tenantId, 'expense_report', 'submit', [
                'amount' => $total,
                'currency' => $report->currency,
            ]);

            if ($policy === null) {
                // No approval chain configured for this report — auto-approve.
                $this->markApproved($report);

                return $report->fresh('items');
            }

            $approval = $this->approvalEngine->createChainedApproval(
                $tenantId,
                $report->user_id,
                'spend',
                'expense_report',
                $report->id,
                $report->title,
                [
                    'action' => 'submit',
                    'attributes' => ['amount' => $total, 'currency' => $report->currency],
                ],
                $policy,
            );

            if (($approval['status'] ?? null) === 'pending') {
                $report->update([
                    'status' => 'awaiting_approval',
                    'approval_id' => $approval['id'],
                ]);
            }

            return $report->fresh('items');
        });
    }

    /**
     * Resource hook fired by ApprovalEngine when the approval chain for an
     * expense report resolves. Runs inside the engine's transaction.
     */
    public function onApprovalResolved(string $tenantId, string $reportId, bool $granted): void
    {
        $report = ExpenseReport::forTenant($tenantId)->find($reportId);

        if (! $report || ! in_array($report->status, ['submitted', 'awaiting_approval'], true)) {
            return;
        }

        if ($granted) {
            $this->markApproved($report);
            $this->notifySubmitter($report, 'expense_approved', [
                'title' => 'Expense report approved',
                'body' => "{$report->report_number} was approved for {$report->currency} {$report->total_amount}.",
                'url' => '/spend/expenses/'.$report->id,
            ]);

            return;
        }

        $reason = DB::table('approvals')
            ->where('tenant_id', $tenantId)
            ->where('resource_type', 'expense_report')
            ->where('resource_id', $reportId)
            ->orderByDesc('updated_at')
            ->value('response');

        $report->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->eventStore->append(
            $tenantId,
            'expense_report',
            $report->id,
            'expense_report.rejected',
            ['report_number' => $report->report_number, 'reason' => $reason]
        );

        $this->notifySubmitter($report, 'expense_rejected', [
            'title' => 'Expense report rejected',
            'body' => $reason !== null && $reason !== ''
                ? "{$report->report_number} was rejected: {$reason}"
                : "{$report->report_number} was rejected.",
            'url' => '/spend/expenses/'.$report->id,
        ]);
    }

    public function voidReport(string $reportId, string $tenantId): ExpenseReport
    {
        return DB::transaction(function () use ($reportId, $tenantId) {
            $report = ExpenseReport::forTenant($tenantId)->findOrFail($reportId);

            if (! in_array($report->status, ['draft', 'submitted'], true)) {
                throw new \LogicException("Only draft or submitted reports can be voided. Current status: {$report->status}");
            }

            $report->update(['status' => 'void']);

            $this->eventStore->append(
                $tenantId,
                'expense_report',
                $report->id,
                'expense_report.voided',
                ['report_number' => $report->report_number]
            );

            return $report->fresh('items');
        });
    }

    public function getSummary(string $tenantId): array
    {
        $statuses = ['draft', 'submitted', 'awaiting_approval', 'approved', 'rejected', 'reimbursed', 'void'];

        $result = array_fill_keys($statuses, ['count' => 0, 'total' => 0]);

        $rows = ExpenseReport::forTenant($tenantId)
            ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $result[$row->status] = ['count' => (int) $row->count, 'total' => (float) $row->total];
        }

        return $result;
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    private function markApproved(ExpenseReport $report): void
    {
        $report->update(['status' => 'approved', 'approved_at' => now()]);

        $this->eventStore->append(
            $report->tenant_id,
            'expense_report',
            $report->id,
            'expense_report.approved',
            [
                'report_number' => $report->report_number,
                'total_amount' => (string) $report->total_amount,
            ]
        );

        app(ReimbursementService::class)->createFromReport($report->fresh());
    }

    private function draftOrFail(string $reportId, string $tenantId): ExpenseReport
    {
        $report = ExpenseReport::forTenant($tenantId)->findOrFail($reportId);

        if (! $report->isDraft()) {
            throw new \LogicException("Expense report must be in draft status. Current status: {$report->status}");
        }

        return $report;
    }

    private function recomputeTotal(ExpenseReport $report): void
    {
        $total = '0';
        foreach ($report->items()->pluck('amount') as $amount) {
            $total = bcadd($total, (string) $amount, 4);
        }

        $report->update(['total_amount' => $total]);
    }

    private function notifySubmitter(ExpenseReport $report, string $eventType, array $payload): void
    {
        try {
            $user = User::find($report->user_id);
            if ($user) {
                $this->notifications->notify($user, $eventType, $payload);
            }
        } catch (\Throwable $e) {
            Log::warning('Expense notification failed', ['report' => $report->id, 'error' => $e->getMessage()]);
        }
    }
}
