<?php

declare(strict_types=1);

namespace App\Services\Spend\Accounting;

use App\Models\AccountingExport;
use App\Models\LedgerEntry;
use App\Services\EventStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Journal exports for external accounting systems. Cursors over
 * ledger_entries (joined to financial + chart accounts) within a posted_at
 * range and writes a CSV to the local disk under spend/{tenant}/exports/.
 *
 * Layouts:
 *   quickbooks_csv — QuickBooks journal import
 *                    (JournalNo,JournalDate,AccountName,Debits,Credits,Description,Name,Currency)
 *   xero_csv       — Xero manual journal
 *                    (Narration,Date,Description,AccountCode,TaxRate,Amount signed)
 *   generic_csv    — every column, for spreadsheets / custom pipelines
 */
class AccountingExportService
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function generate(
        string $tenantId,
        string $exportType,
        string $from,
        string $to,
        string $requestedBy,
    ): AccountingExport {
        if (! in_array($exportType, AccountingExport::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown export type: {$exportType}");
        }

        $export = AccountingExport::create([
            'tenant_id' => $tenantId,
            'export_type' => $exportType,
            'period_start' => $from,
            'period_end' => $to,
            'status' => 'pending',
            'requested_by' => $requestedBy,
        ]);

        try {
            $path = "spend/{$tenantId}/exports/{$export->id}.csv";
            $rowCount = $this->writeCsv($tenantId, $exportType, $from, $to, $path);

            $export->update([
                'status' => 'generated',
                'file_path' => $path,
                'row_count' => $rowCount,
            ]);

            $this->eventStore->append($tenantId, 'accounting_export', $export->id, 'accounting.export_generated', [
                'export_type' => $exportType,
                'period_start' => $from,
                'period_end' => $to,
                'row_count' => $rowCount,
            ]);
        } catch (\Throwable $e) {
            $export->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        return $export->fresh();
    }

    /** Streamed CSV download (see ComplianceController::auditExport precedent). */
    public function streamDownload(AccountingExport $export): StreamedResponse
    {
        if (! $export->isGenerated() || ! $export->file_path) {
            throw new \LogicException("Export is not ready for download. Status: {$export->status}");
        }

        $disk = Storage::disk('local');
        $path = $export->file_path;

        $filename = sprintf(
            '%s-%s-%s.csv',
            str_replace('_csv', '', $export->export_type),
            $export->period_start->toDateString(),
            $export->period_end->toDateString(),
        );

        return response()->streamDownload(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            if ($stream !== null) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ------------------------------------------------------------------ //
    //  CSV building
    // ------------------------------------------------------------------ //

    /** @return int data rows written (header excluded) */
    private function writeCsv(string $tenantId, string $exportType, string $from, string $to, string $path): int
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $this->header($exportType));

        $rows = 0;

        $entries = LedgerEntry::forTenant($tenantId)
            ->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->with(['account', 'chartAccount'])
            ->orderBy('posted_at')
            ->orderBy('transaction_id')
            ->cursor();

        foreach ($entries as $entry) {
            fputcsv($out, $this->row($exportType, $entry));
            $rows++;
        }

        rewind($out);
        Storage::disk('local')->put($path, stream_get_contents($out) ?: '');
        fclose($out);

        return $rows;
    }

    private function header(string $exportType): array
    {
        return match ($exportType) {
            'quickbooks_csv' => ['JournalNo', 'JournalDate', 'AccountName', 'Debits', 'Credits', 'Description', 'Name', 'Currency'],
            'xero_csv' => ['Narration', 'Date', 'Description', 'AccountCode', 'TaxRate', 'Amount'],
            'generic_csv' => [
                'transaction_id', 'posted_at', 'entry_type', 'side', 'amount', 'currency',
                'account_name', 'account_number', 'chart_code', 'chart_name',
                'description', 'reference_type', 'reference_id',
            ],
            default => throw new \InvalidArgumentException("Unknown export type: {$exportType}"),
        };
    }

    private function row(string $exportType, LedgerEntry $entry): array
    {
        $accountName = $entry->chartAccount->name ?? $entry->account->name ?? 'Unknown';
        $accountCode = $entry->chartAccount->code ?? $entry->account->account_number ?? '';
        $amount = (string) $entry->amount;

        return match ($exportType) {
            'quickbooks_csv' => [
                $entry->transaction_id,
                $entry->posted_at?->toDateString(),
                $accountName,
                $entry->side === 'debit' ? $amount : '',
                $entry->side === 'credit' ? $amount : '',
                $entry->description,
                '', // Name (entity) — not tracked at ledger level
                $entry->currency,
            ],
            'xero_csv' => [
                $entry->transaction_id,
                $entry->posted_at?->toDateString(),
                $entry->description,
                $accountCode,
                'Tax Exempt',
                $entry->side === 'debit' ? $amount : '-'.$amount,
            ],
            'generic_csv' => [
                $entry->transaction_id,
                $entry->posted_at?->toIso8601String(),
                $entry->entry_type,
                $entry->side,
                $amount,
                $entry->currency,
                $entry->account->name ?? '',
                $entry->account->account_number ?? '',
                $entry->chartAccount->code ?? '',
                $entry->chartAccount->name ?? '',
                $entry->description,
                $entry->reference_type,
                $entry->reference_id,
            ],
            default => throw new \InvalidArgumentException("Unknown export type: {$exportType}"),
        };
    }
}
