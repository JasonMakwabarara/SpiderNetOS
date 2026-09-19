<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SpendDocument;
use App\Services\CostGovernor;
use App\Services\EventStore;
use App\Services\Spend\CategorySuggestionService;
use App\Services\Spend\DocumentExtractionService;
use App\Services\Spend\ReceiptHeuristics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/**
 * Extracts structured fields from an uploaded spend document.
 *
 * Pipeline: LLM extraction (CostGovernor-gated) -> ReceiptHeuristics (when
 * the document has an extractable text layer) -> needs_review.
 *
 * Extraction QUALITY problems never throw out of handle(); only
 * infrastructure failures (DB down, etc.) bubble up and trigger retries.
 */
class ExtractSpendDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $documentId,
    ) {
        $this->onQueue('intelligence');
    }

    public function handle(
        DocumentExtractionService $extractor,
        ReceiptHeuristics $heuristics,
        CategorySuggestionService $categories,
        EventStore $eventStore,
    ): void {
        $doc = SpendDocument::find($this->documentId);

        if (! $doc || in_array($doc->status, ['confirmed', 'extracted'], true)) {
            return;
        }

        $doc->update([
            'status' => 'extracting',
            'attempts' => $doc->attempts + 1,
            'error' => null,
        ]);

        $result = null;

        if ($this->costGovernorAllows($doc->tenant_id)) {
            $result = $extractor->extract($doc);
        }

        if ($result === null) {
            $text = $this->extractableText($doc);
            if ($text !== null && trim($text) !== '') {
                try {
                    $result = $heuristics->extract($text);
                } catch (\Throwable $e) {
                    Log::warning('ExtractSpendDocumentJob: heuristics failed', [
                        'document_id' => $doc->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $reviewThreshold = (float) config('spend.extraction.review_threshold', 0.6);

        if ($result === null) {
            $doc->update([
                'status' => 'needs_review',
                'error' => 'No extraction method produced a result; manual entry required.',
            ]);

            $this->emitExtractedEvent($eventStore, $doc->fresh(), null);

            return;
        }

        $extraction = [
            'fields' => $result['fields'],
            'line_items' => $result['line_items'] ?? [],
            'overall_confidence' => $result['overall_confidence'],
        ];

        // Inline category suggestion from the extracted merchant.
        $merchant = (string) ($result['fields']['merchant']['value'] ?? '');
        if ($merchant !== '') {
            try {
                $extraction['suggested_category'] = $categories->suggest(
                    $doc->tenant_id,
                    $merchant,
                    (string) ($doc->original_filename ?? ''),
                    $result['fields']['total']['value'] ?? null,
                );
            } catch (\Throwable $e) {
                Log::warning('ExtractSpendDocumentJob: category suggestion failed', [
                    'document_id' => $doc->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $status = $result['overall_confidence'] >= $reviewThreshold ? 'extracted' : 'needs_review';

        $doc->update([
            'status' => $status,
            'extraction' => $extraction,
            'extraction_method' => $result['method'],
            'extraction_model' => $result['model'] ?? null,
            'extraction_cost_usd' => $result['cost_usd'] ?? 0,
            'extraction_latency_ms' => $result['latency_ms'] ?? null,
        ]);

        $this->emitExtractedEvent($eventStore, $doc->fresh(), $result);
    }

    public function failed(\Throwable $exception): void
    {
        try {
            $doc = SpendDocument::find($this->documentId);
            if (! $doc) {
                return;
            }

            $doc->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            app(EventStore::class)->append(
                $doc->tenant_id,
                'spend_document',
                $doc->id,
                'spend_document.extraction_failed',
                [
                    'original_filename' => $doc->original_filename,
                    'attempts' => $doc->attempts,
                    'error' => mb_substr($exception->getMessage(), 0, 500),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('ExtractSpendDocumentJob: failed() handler errored', [
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function costGovernorAllows(string $tenantId): bool
    {
        try {
            $estimate = (float) config('spend.extraction.cost_estimate', 0.01);
            $check = app(CostGovernor::class)->canExecute($tenantId, $estimate);

            return (bool) ($check['allowed'] ?? true);
        } catch (\Throwable) {
            return true;
        }
    }

    /** Text layer for heuristic parsing: text/* files and parseable PDFs. */
    private function extractableText(SpendDocument $doc): ?string
    {
        try {
            if (str_starts_with($doc->mime_type, 'text/')) {
                return Storage::disk($doc->disk)->get($doc->path);
            }

            if ($doc->mime_type === 'application/pdf' && class_exists(Parser::class)) {
                $contents = Storage::disk($doc->disk)->get($doc->path);
                if ($contents === null || $contents === '') {
                    return null;
                }

                return (new Parser)->parseContent($contents)->getText();
            }
        } catch (\Throwable $e) {
            Log::info('ExtractSpendDocumentJob: text layer unavailable', [
                'document_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function emitExtractedEvent(EventStore $eventStore, SpendDocument $doc, ?array $result): void
    {
        try {
            $eventStore->append(
                $doc->tenant_id,
                'spend_document',
                $doc->id,
                'spend_document.extracted',
                [
                    'status' => $doc->status,
                    'method' => $result['method'] ?? null,
                    'model' => $result['model'] ?? null,
                    'overall_confidence' => $result['overall_confidence'] ?? null,
                    'cost_usd' => $result['cost_usd'] ?? 0,
                    'suggested_category' => $doc->extraction['suggested_category'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('ExtractSpendDocumentJob: event emission failed', [
                'document_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
