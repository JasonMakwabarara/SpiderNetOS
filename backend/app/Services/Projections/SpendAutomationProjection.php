<?php

declare(strict_types=1);

namespace App\Services\Projections;

use App\Jobs\EvaluateSpendPolicyJob;
use App\Models\Event;
use App\Services\Spend\CategorySuggestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Spend automation projector.
 *
 *  - expense_report.submitted   -> queue deep policy evaluation (afterCommit,
 *                                  so the report row is visible to the job)
 *  - spend_document.confirmed   -> learn merchant -> category mapping
 *  - expense.category_confirmed -> learn merchant -> category mapping
 *
 * The merchant map upsert: confirming the same category again bumps
 * confirm_count (confidence grows); confirming a different category
 * overwrites the row and resets confirm_count to 1.
 */
class SpendAutomationProjection
{
    public function accepts(Event $event): bool
    {
        return in_array($event->event_type, [
            'expense_report.submitted',
            'spend_document.confirmed',
            'expense.category_confirmed',
        ], true);
    }

    public function handle(Event $event): void
    {
        match ($event->event_type) {
            'expense_report.submitted' => $this->handleSubmitted($event),
            'spend_document.confirmed',
            'expense.category_confirmed' => $this->learnMerchantCategory($event),
            default => null,
        };
    }

    private function handleSubmitted(Event $event): void
    {
        EvaluateSpendPolicyJob::dispatch($event->tenant_id, $event->aggregate_id)->afterCommit();
    }

    private function learnMerchantCategory(Event $event): void
    {
        try {
            $payload = $event->payload ?? [];

            $merchant = $payload['merchant']
                ?? ($payload['final_fields']['merchant'] ?? null);
            $category = $payload['category']
                ?? ($payload['final_fields']['category'] ?? null);
            $chartAccountCode = $payload['chart_account_code']
                ?? ($payload['final_fields']['chart_account_code'] ?? null);

            if (!is_string($merchant) || !is_string($category) || $category === '') {
                return;
            }

            $normalized = CategorySuggestionService::normalizeMerchant($merchant);
            if ($normalized === '') {
                return;
            }

            $now = now();

            $row = DB::table('merchant_category_map')
                ->where('tenant_id', $event->tenant_id)
                ->where('merchant_normalized', $normalized)
                ->first();

            if (!$row) {
                DB::table('merchant_category_map')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $event->tenant_id,
                    'merchant_normalized' => $normalized,
                    'category' => $category,
                    'chart_account_code' => is_string($chartAccountCode) ? $chartAccountCode : null,
                    'confirm_count' => 1,
                    'last_confirmed_at' => $now,
                    'source' => 'user_confirm',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return;
            }

            if ($row->category === $category) {
                DB::table('merchant_category_map')->where('id', $row->id)->update([
                    'confirm_count' => (int) $row->confirm_count + 1,
                    'chart_account_code' => is_string($chartAccountCode)
                        ? $chartAccountCode
                        : $row->chart_account_code,
                    'last_confirmed_at' => $now,
                    'updated_at' => $now,
                ]);

                return;
            }

            // Category changed: overwrite and reset the learning counter.
            DB::table('merchant_category_map')->where('id', $row->id)->update([
                'category' => $category,
                'chart_account_code' => is_string($chartAccountCode) ? $chartAccountCode : null,
                'confirm_count' => 1,
                'last_confirmed_at' => $now,
                'source' => 'user_confirm',
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // Projectors run inside the event append transaction — a learning
            // failure must never poison the source event.
            Log::warning('SpendAutomationProjection: merchant map upsert failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
