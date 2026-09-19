<?php

declare(strict_types=1);

namespace App\Services\Spend;

use App\Models\SpendDocument;
use App\Services\CostGovernor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/**
 * LLM-backed structured extraction for spend documents (receipts/bills).
 *
 * Talks to the inference plane at {services.inference.url}/v1/extract-document
 * (same caller pattern as TransformationEngine). Returns null on ANY failure —
 * the caller (ExtractSpendDocumentJob) falls back to ReceiptHeuristics.
 */
class DocumentExtractionService
{
    private const FIELD_KEYS = ['merchant', 'date', 'total', 'tax', 'currency'];

    /**
     * @return ?array{fields: array, line_items: array, overall_confidence: float, method: string, model: ?string, cost_usd: float, latency_ms: int}
     */
    public function extract(SpendDocument $doc): ?array
    {
        $inferenceUrl = (string) config('services.inference.url', '');
        if ($inferenceUrl === '') {
            return null;
        }

        $request = $this->buildRequest($doc);
        if ($request === null) {
            return null;
        }

        $started = microtime(true);

        try {
            $response = Http::timeout(45)
                ->retry(1, 1000)
                ->post(rtrim($inferenceUrl, '/').'/v1/extract-document', $request);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();
            if (! is_array($body)) {
                return null;
            }

            $fields = $this->normalizeFields($body['fields'] ?? null);
            if ($fields === null) {
                return null;
            }

            $latencyMs = (int) round((microtime(true) - $started) * 1000);
            $costUsd = is_numeric($body['cost_usd'] ?? null)
                ? (float) $body['cost_usd']
                : (float) config('spend.extraction.cost_estimate', 0.01);

            $overall = is_numeric($body['overall_confidence'] ?? null)
                ? max(0.0, min(1.0, (float) $body['overall_confidence']))
                : $this->averageConfidence($fields);

            $this->recordCost($doc, $costUsd, $body['model'] ?? null, $latencyMs);

            return [
                'fields' => $fields,
                'line_items' => is_array($body['line_items'] ?? null) ? $body['line_items'] : [],
                'overall_confidence' => $overall,
                'method' => 'llm',
                'model' => is_string($body['model'] ?? null)
                    ? $body['model']
                    : config('spend.extraction.model'),
                'cost_usd' => $costUsd,
                'latency_ms' => $latencyMs,
            ];
        } catch (\Throwable $e) {
            Log::info('DocumentExtractionService: extraction call failed', [
                'document_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Read the stored file and shape the inference request. Images go up as
     * base64; PDFs are reduced to their text layer when a parser is
     * installed, otherwise we bail (null => heuristic/needs_review path).
     */
    private function buildRequest(SpendDocument $doc): ?array
    {
        try {
            $contents = Storage::disk($doc->disk)->get($doc->path);
        } catch (\Throwable $e) {
            Log::warning('DocumentExtractionService: unable to read document file', [
                'document_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($contents === null || $contents === '') {
            return null;
        }

        $base = [
            'tenant_id' => $doc->tenant_id,
            'document_id' => $doc->id,
            'kind' => $doc->kind,
            'mime_type' => $doc->mime_type,
            'model' => config('spend.extraction.model'),
            'fields' => array_merge(self::FIELD_KEYS, ['line_items']),
        ];

        if (str_starts_with($doc->mime_type, 'image/')) {
            return $base + ['mode' => 'image', 'content_base64' => base64_encode($contents)];
        }

        if ($doc->mime_type === 'application/pdf') {
            if (! class_exists(Parser::class)) {
                return null;
            }

            try {
                $text = (new Parser)->parseContent($contents)->getText();
            } catch (\Throwable) {
                return null;
            }

            if (trim($text) === '') {
                return null;
            }

            return $base + ['mode' => 'text', 'text' => $text];
        }

        if (str_starts_with($doc->mime_type, 'text/')) {
            return $base + ['mode' => 'text', 'text' => $contents];
        }

        return null;
    }

    /**
     * @return ?array<string, array{value: mixed, confidence: float}>
     */
    private function normalizeFields(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $fields = [];

        foreach ($raw as $key => $spec) {
            if (! is_string($key)) {
                continue;
            }

            if (is_array($spec) && array_key_exists('value', $spec)) {
                $fields[$key] = [
                    'value' => $spec['value'],
                    'confidence' => is_numeric($spec['confidence'] ?? null)
                        ? max(0.0, min(1.0, (float) $spec['confidence']))
                        : 0.5,
                ];
            } elseif (is_scalar($spec)) {
                $fields[$key] = ['value' => $spec, 'confidence' => 0.5];
            }
        }

        return $fields === [] ? null : $fields;
    }

    private function averageConfidence(array $fields): float
    {
        $confidences = array_map(fn (array $f) => (float) $f['confidence'], $fields);

        return $confidences === [] ? 0.0 : round(array_sum($confidences) / count($confidences), 4);
    }

    private function recordCost(SpendDocument $doc, float $costUsd, mixed $model, int $latencyMs): void
    {
        if ($costUsd <= 0) {
            return;
        }

        try {
            app(CostGovernor::class)->recordUsage(
                $doc->tenant_id,
                'spend_document_extraction',
                $costUsd,
                [
                    'document_id' => $doc->id,
                    'model' => is_string($model) ? $model : null,
                    'duration_ms' => $latencyMs,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('DocumentExtractionService: cost recording failed', [
                'document_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
