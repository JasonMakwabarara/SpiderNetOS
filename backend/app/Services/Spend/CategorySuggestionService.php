<?php

declare(strict_types=1);

namespace App\Services\Spend;

use App\Models\ExpenseCategory;
use App\Services\CostGovernor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Category suggestion cascade:
 *   1. merchant_category_map memory (user confirmations; confidence grows
 *      with confirm_count: min(0.99, 0.7 + 0.05 * confirm_count))
 *   2. keyword rules from config('spend.keyword_rules')       (0.6)
 *   3. inference-plane /v1/classify, CostGovernor-gated
 *   4. ('uncategorized', null, 0.3, 'fallback')
 *
 * Never throws. Suggestions below category_confidence_threshold carry
 * requires_user_pick = true.
 */
class CategorySuggestionService
{
    private const CLASSIFY_COST_ESTIMATE = 0.001;

    /**
     * @return array{category: string, chart_account_code: ?string, confidence: float, source: string, requires_user_pick: bool}
     */
    public function suggest(
        string $tenantId,
        string $merchant,
        string $description = '',
        float|string|null $amount = null,
    ): array {
        try {
            $suggestion = $this->fromMemory($tenantId, $merchant)
                ?? $this->fromKeywordRules($merchant, $description)
                ?? $this->fromClassifier($tenantId, $merchant, $description, $amount)
                ?? $this->fallback();
        } catch (\Throwable $e) {
            Log::warning('CategorySuggestionService: cascade failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            $suggestion = $this->fallback();
        }

        $threshold = (float) config('spend.category_confidence_threshold', 0.75);
        $suggestion['requires_user_pick'] = $suggestion['confidence'] < $threshold;

        return $suggestion;
    }

    /**
     * Normalize a merchant string for memory lookups: lowercase, strip
     * punctuation, store numbers and corporate suffixes (inc/llc/ltd/...).
     */
    public static function normalizeMerchant(string $merchant): string
    {
        $m = mb_strtolower(trim($merchant));
        $m = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $m) ?? '';
        $m = preg_replace('/\b(inc|llc|ltd|co|corp|plc|gmbh|sarl|pty)\b/u', ' ', $m) ?? '';
        // Store numbers: standalone digit runs of 2+ (e.g. "starbucks 04521").
        $m = preg_replace('/\b\d{2,}\b/', ' ', $m) ?? '';

        return trim(preg_replace('/\s+/', ' ', $m) ?? '');
    }

    private function fromMemory(string $tenantId, string $merchant): ?array
    {
        $normalized = self::normalizeMerchant($merchant);
        if ($normalized === '') {
            return null;
        }

        $row = DB::table('merchant_category_map')
            ->where('tenant_id', $tenantId)
            ->where('merchant_normalized', $normalized)
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'category' => $row->category,
            'chart_account_code' => $row->chart_account_code,
            'confidence' => min(0.99, 0.7 + 0.05 * (int) $row->confirm_count),
            'source' => 'memory',
        ];
    }

    private function fromKeywordRules(string $merchant, string $description): ?array
    {
        $haystack = mb_strtolower($merchant.' '.$description);

        foreach ((array) config('spend.keyword_rules', []) as $category => $keywords) {
            foreach ((array) $keywords as $keyword) {
                if (str_contains($haystack, mb_strtolower((string) $keyword))) {
                    return [
                        'category' => (string) $category,
                        'chart_account_code' => null,
                        'confidence' => 0.6,
                        'source' => 'keyword',
                    ];
                }
            }
        }

        return null;
    }

    private function fromClassifier(
        string $tenantId,
        string $merchant,
        string $description,
        float|string|null $amount,
    ): ?array {
        $inferenceUrl = (string) config('services.inference.url', '');
        if ($inferenceUrl === '') {
            return null;
        }

        if (! $this->costGovernorAllows($tenantId)) {
            return null;
        }

        $enum = $this->categoryEnum($tenantId);

        try {
            $response = Http::timeout(20)
                ->retry(2, 500)
                ->post(rtrim($inferenceUrl, '/').'/v1/classify', [
                    'tenant_id' => $tenantId,
                    'text' => trim($merchant.'. '.$description),
                    'intent_enum' => $enum,
                    'amount' => $amount !== null ? (float) $amount : null,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();
            if (! is_array($body)) {
                return null;
            }

            $category = $body['category'] ?? $body['label'] ?? null;
            if (! is_string($category) || ! in_array($category, $enum, true)) {
                return null;
            }

            $confidence = is_numeric($body['confidence'] ?? $body['score'] ?? null)
                ? max(0.0, min(1.0, (float) ($body['confidence'] ?? $body['score'])))
                : 0.5;

            $this->recordClassifyCost($tenantId, $body);

            return [
                'category' => $category,
                'chart_account_code' => null,
                'confidence' => $confidence,
                'source' => 'llm',
            ];
        } catch (\Throwable $e) {
            Log::info('CategorySuggestionService: classify call failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Default categories unioned with the tenant's active expense category slugs. */
    private function categoryEnum(string $tenantId): array
    {
        $defaults = (array) config('spend.categories', []);

        try {
            $tenantSlugs = ExpenseCategory::forTenant($tenantId)
                ->where('active', true)
                ->pluck('slug')
                ->all();
        } catch (\Throwable) {
            $tenantSlugs = [];
        }

        return array_values(array_unique(array_merge($defaults, $tenantSlugs)));
    }

    private function costGovernorAllows(string $tenantId): bool
    {
        try {
            $check = app(CostGovernor::class)->canExecute($tenantId, self::CLASSIFY_COST_ESTIMATE);

            return (bool) ($check['allowed'] ?? true);
        } catch (\Throwable) {
            // Governor being unavailable must not block suggestion flow.
            return true;
        }
    }

    private function recordClassifyCost(string $tenantId, array $body): void
    {
        try {
            $cost = is_numeric($body['cost_usd'] ?? null)
                ? (float) $body['cost_usd']
                : self::CLASSIFY_COST_ESTIMATE;

            if ($cost > 0) {
                app(CostGovernor::class)->recordUsage($tenantId, 'spend_category_classify', $cost, [
                    'model' => $body['model'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('CategorySuggestionService: cost recording failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fallback(): array
    {
        return [
            'category' => 'uncategorized',
            'chart_account_code' => null,
            'confidence' => 0.3,
            'source' => 'fallback',
        ];
    }
}
