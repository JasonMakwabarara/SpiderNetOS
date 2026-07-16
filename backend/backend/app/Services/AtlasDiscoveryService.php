<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decides whether Atlas should ask discovery questions or act on a request.
 */
class AtlasDiscoveryService
{
    private const VAGUE_PATTERNS = [
        'help me',
        'get started',
        'what should i',
        'where do i start',
        'automate',
        'not sure',
        'don\'t know',
        "don't know",
        'what do i need',
    ];

    /**
     * @return array{mode: string, questions?: array<int, string>, suggested_next?: array<string, mixed>, profile_pct?: int}
     */
    public function evaluate(string $tenantId, string $message, ?PackGrowthService $growth = null): array
    {
        $profile = $this->profileForTenant($tenantId);
        $pct = (int) ($profile['discovery_complete_pct'] ?? 0);
        $lower = strtolower(trim($message));

        $profileComplete = $pct >= 60 && ! $this->missingCriticalFields($profile);
        $shouldDiscover = ! $profileComplete
            || $this->matchesVaguePatterns($lower)
            || ($pct < 60 && strlen($lower) < 25);

        if (! $shouldDiscover) {
            return ['mode' => 'act', 'profile_pct' => $pct];
        }

        $questions = $this->nextQuestions($profile);
        $suggested = $this->suggestedNext($profile, $tenantId, $growth);

        return [
            'mode' => 'discover',
            'questions' => $questions,
            'suggested_next' => $suggested,
            'profile_pct' => $pct,
        ];
    }

    /**
     * Persist answers extracted from user messages (best-effort heuristics).
     */
    public function absorbAnswer(string $tenantId, string $message): void
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return;
        }

        $lower = strtolower($message);
        $updates = [];

        if (preg_match('/\b(solo|just me|1 person|myself)\b/', $lower)) {
            $updates['employee_count_band'] = 'solo';
        } elseif (preg_match('/\b(2-10|small team|few people)\b/', $lower)) {
            $updates['employee_count_band'] = '2-10';
        } elseif (preg_match('/\b(11-50|medium)\b/', $lower)) {
            $updates['employee_count_band'] = '11-50';
        }

        if (preg_match('/\b(invoice|invoic|billing customer|send bills)\b/', $lower)) {
            $updates['issues_invoices'] = true;
        }
        if (preg_match('/\b(email|customer data|personal data|pii|privacy)\b/', $lower)) {
            $updates['data_handles_pii'] = true;
        }
        if (preg_match('/\b(contractor|freelanc)\b/', $lower)) {
            $updates['hires_contractors'] = true;
        }

        if (preg_match('/\b(real estate|property|estate agency)\b/', $lower)) {
            $updates['industry'] = 'real_estate';
        } elseif (preg_match('/\b(retail|shop|store|ecommerce)\b/', $lower)) {
            $updates['industry'] = 'retail';
        } elseif (preg_match('/\b(consult|agency|professional service|law firm|accounting)\b/', $lower)) {
            $updates['industry'] = 'professional_services';
        } elseif (preg_match('/\b(health|clinic|medical|dental)\b/', $lower)) {
            $updates['industry'] = 'healthcare';
        } elseif (preg_match('/\b(construction|builder|trades)\b/', $lower)) {
            $updates['industry'] = 'construction';
        } elseif (preg_match('/\b(software|tech|saas)\b/', $lower)) {
            $updates['industry'] = 'technology';
        }

        if (strlen(trim($message)) > 20 && ! str_starts_with($lower, '/')) {
            $updates['biggest_time_drain'] = mb_substr(trim($message), 0, 500);
        }

        if (empty($updates)) {
            return;
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();
        if (! $row) {
            $updates['tenant_id'] = $tenantId;
            $updates['created_at'] = now();
            $updates['updated_at'] = now();
            $updates['discovery_complete_pct'] = $this->computePct(array_merge([
                'industry' => null,
                'employee_count_band' => null,
                'issues_invoices' => false,
                'data_handles_pii' => false,
                'biggest_time_drain' => null,
            ], $updates));
            DB::table('tenant_business_profiles')->insert($updates);

            return;
        }

        $merged = array_merge((array) $row, $updates);
        $updates['discovery_complete_pct'] = $this->computePct($merged);
        $updates['updated_at'] = now();
        DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->update($updates);
    }

    /**
     * @return array<string, mixed>
     */
    public function profileForTenant(string $tenantId): array
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return ['discovery_complete_pct' => 0];
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();

        return $row ? (array) $row : ['discovery_complete_pct' => 0];
    }

    private function matchesVaguePatterns(string $lower): bool
    {
        foreach (self::VAGUE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function missingCriticalFields(array $profile): bool
    {
        return empty($profile['employee_count_band'])
            && empty($profile['biggest_time_drain'])
            && empty($profile['industry']);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<int, string>
     */
    private function nextQuestions(array $profile): array
    {
        if (empty($profile['biggest_time_drain'])) {
            return ['What task eats the most time in your week?'];
        }
        if (empty($profile['employee_count_band'])) {
            return ['Are you working solo, or do you have a team?'];
        }
        if (! isset($profile['issues_invoices'])) {
            return ['Do you send invoices or quotes to customers?'];
        }

        return ['What would success look like if SpiderNetOS handled that for you?'];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function suggestedNext(array $profile, ?string $tenantId = null, ?PackGrowthService $growth = null): array
    {
        if ($tenantId && $growth) {
            $fromGrowth = $growth->suggestedNextForProfile($tenantId);
            if ($fromGrowth) {
                $growth->recordSignalThrottled($tenantId, 'atlas_suggested', $fromGrowth['pack'] ?? null, [
                    'relevance_score' => $fromGrowth['relevance_score'] ?? null,
                ]);
                return $fromGrowth;
            }
        }

        if (! empty($profile['issues_invoices'])) {
            return [
                'type' => 'automation',
                'label' => 'Chase overdue invoices',
                'pack' => 'financial-services',
                'path' => '/financial',
            ];
        }

        if (! empty($profile['biggest_time_drain']) && str_contains(strtolower((string) $profile['biggest_time_drain']), 'lead')) {
            return [
                'type' => 'automation',
                'label' => 'Capture and follow up on leads',
                'pack' => 'sales-crm',
                'path' => '/sales',
            ];
        }

        return [
            'type' => 'automation',
            'label' => 'Run your first automation in 5 minutes',
            'pack' => null,
            'path' => '/operate/first-win',
        ];
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function computePct(array $profile): int
    {
        $fields = ['industry', 'employee_count_band', 'biggest_time_drain'];
        $filled = 0;
        foreach ($fields as $field) {
            if (! empty($profile[$field])) {
                $filled++;
            }
        }
        if (! empty($profile['issues_invoices']) || ! empty($profile['data_handles_pii'])) {
            $filled++;
        }

        return (int) min(100, round(($filled / 4) * 100));
    }

    public function refreshCompletionPct(string $tenantId): void
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return;
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();
        if (! $row) {
            return;
        }

        DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->update([
            'discovery_complete_pct' => $this->computePct((array) $row),
            'updated_at' => now(),
        ]);
    }
}
