<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Maps SME profile, usage, and feedback into growing, personalized pack recommendations.
 */
class PackGrowthService
{
    /** @var array<string, array<string, int>> */
    private const INDUSTRY_AFFINITY = [
        'professional_services' => [
            'financial-services' => 28,
            'sales-crm' => 22,
            'compliance-radar' => 18,
        ],
        'retail' => [
            'sales-crm' => 30,
            'financial-services' => 20,
            'compliance-radar' => 15,
        ],
        'real_estate' => [
            'real-estate-crm' => 35,
            'sales-crm' => 18,
            'compliance-radar' => 12,
        ],
        'construction' => [
            'financial-services' => 25,
            'compliance-radar' => 22,
            'sales-crm' => 15,
        ],
        'healthcare' => [
            'compliance-radar' => 30,
            'financial-services' => 18,
        ],
        'technology' => [
            'sales-crm' => 25,
            'financial-services' => 20,
            'compliance-radar' => 18,
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const OUTCOME_VARIANTS = [
        'financial-services' => [
            'professional_services' => [
                'Track billable work and chase overdue client invoices',
                'See retainer burn-down without a spreadsheet',
            ],
            'retail' => [
                'Reconcile daily takings with supplier payments',
                'Spot cash gaps before stock reorders',
            ],
            'construction' => [
                'Track job costing and progress billing in one place',
                'Flag large subcontractor payments before they hit cash',
            ],
        ],
        'sales-crm' => [
            'professional_services' => [
                'Never miss a proposal follow-up',
                'See which deals need a nudge today',
            ],
            'retail' => [
                'Follow up on quotes and repeat customers automatically',
                'See who is about to churn before they leave',
            ],
            'real_estate' => [
                'Qualify buyers and sellers without losing threads',
                'Schedule viewings with less back-and-forth',
            ],
        ],
        'compliance-radar' => [
            'healthcare' => [
                'Surface patient-data obligations in plain English',
                'One next step per topic — not a legal textbook',
            ],
            'construction' => [
                'Know contractor and site-safety basics that apply to you',
                'Track agreements and insurance renewals',
            ],
        ],
        'real-estate-crm' => [
            'real_estate' => [
                'Capture leads from portals into one pipeline',
                'Move offers from submission to close with clear stages',
            ],
        ],
    ];

    /** @var array<string, string> */
    private const PAIN_PACK_MAP = [
        'invoice' => 'financial-services',
        'bill' => 'financial-services',
        'cash' => 'financial-services',
        'payment' => 'financial-services',
        'lead' => 'sales-crm',
        'pipeline' => 'sales-crm',
        'follow' => 'sales-crm',
        'crm' => 'sales-crm',
        'compliance' => 'compliance-radar',
        'privacy' => 'compliance-radar',
        'gdpr' => 'compliance-radar',
        'contractor' => 'compliance-radar',
        'property' => 'real-estate-crm',
        'viewing' => 'real-estate-crm',
        'estate' => 'real-estate-crm',
    ];

    public function recordSignal(string $tenantId, string $signalType, ?string $packId = null, array $context = [], int $weight = 1): void
    {
        if (! Schema::hasTable('tenant_pack_signals')) {
            return;
        }

        DB::table('tenant_pack_signals')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'pack_id' => $packId,
            'signal_type' => $signalType,
            'context' => json_encode($context),
            'weight' => $weight,
            'created_at' => now(),
        ]);

        $this->absorbIntoProfile($tenantId, $signalType, $packId, $context, $weight);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function personalizeCatalogue(string $tenantId, array $entries, array $installedPackIds = []): array
    {
        $profile = $this->profileForTenant($tenantId);
        $affinities = $this->packAffinities($tenantId, $profile);

        foreach ($entries as &$entry) {
            $packId = (string) ($entry['pack_id'] ?? '');
            $score = $this->scorePack($packId, $profile, $affinities, $installedPackIds);
            $industry = $this->normalizeIndustry((string) ($profile['industry'] ?? ''));

            $entry['relevance_score'] = $score;
            $entry['recommended'] = $score >= 55 && ! in_array($packId, $installedPackIds, true);
            $entry['growth_reason'] = $this->growthReason($packId, $profile, $score);
            $entry['customer_outcomes'] = $this->personalizedOutcomes(
                $packId,
                $industry,
                (array) ($entry['customer_outcomes'] ?? [])
            );
        }
        unset($entry);

        usort($entries, fn (array $a, array $b) => ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0));

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recommendations(string $tenantId, array $catalogueEntries, array $installedPackIds = []): array
    {
        $personalized = $this->personalizeCatalogue($tenantId, $catalogueEntries, $installedPackIds);

        return array_values(array_filter($personalized, fn (array $p) => ($p['recommended'] ?? false)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function suggestedNextForProfile(string $tenantId): ?array
    {
        $profile = $this->profileForTenant($tenantId);
        $installed = DB::table('feature_packs')
            ->where('tenant_id', $tenantId)
            ->pluck('pack_id')
            ->all();

        $affinities = $this->packAffinities($tenantId, $profile);
        $candidateIds = array_unique(array_merge(
            array_keys($affinities),
            ['financial-services', 'sales-crm', 'compliance-radar', 'real-estate-crm'],
        ));
        $bestPack = null;
        $bestScore = 0;

        foreach ($candidateIds as $packId) {
            if (in_array($packId, $installed, true)) {
                continue;
            }
            $score = $this->scorePack($packId, $profile, $affinities, $installed);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPack = $packId;
            }
        }

        if ($bestPack && $bestScore >= 40) {
            return [
                'type' => 'pack',
                'label' => $this->labelForPack($bestPack),
                'pack' => $bestPack,
                'path' => $this->entryPath($bestPack),
                'relevance_score' => $bestScore,
                'growth_reason' => $this->growthReason($bestPack, $profile, $bestScore),
            ];
        }

        return null;
    }

    public function recordFeedback(string $tenantId, string $packId, string $sentiment, ?string $note = null, ?string $outcome = null): void
    {
        $weight = $sentiment === 'positive' ? 3 : ($sentiment === 'negative' ? -4 : 1);
        $this->recordSignal($tenantId, 'feedback_'.$sentiment, $packId, array_filter([
            'note' => $note,
            'outcome' => $outcome,
        ]), abs($weight));
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

    /**
     * @param array<string, mixed> $profile
     * @return array<string, int>
     */
    private function packAffinities(string $tenantId, array $profile): array
    {
        $learned = $this->decodeJson($profile['learned_signals'] ?? null);
        $stored = (array) ($learned['pack_affinities'] ?? []);
        $computed = $this->computeAffinitiesFromProfile($profile);
        $fromSignals = $this->affinitiesFromSignals($tenantId);

        $merged = $computed;
        foreach ($stored as $packId => $score) {
            $merged[$packId] = ($merged[$packId] ?? 0) + (int) $score;
        }
        foreach ($fromSignals as $packId => $score) {
            $merged[$packId] = ($merged[$packId] ?? 0) + $score;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, int>
     */
    private function computeAffinitiesFromProfile(array $profile): array
    {
        $scores = [];
        $industry = $this->normalizeIndustry((string) ($profile['industry'] ?? ''));

        foreach (self::INDUSTRY_AFFINITY[$industry] ?? [] as $packId => $boost) {
            $scores[$packId] = ($scores[$packId] ?? 0) + $boost;
        }

        if (! empty($profile['issues_invoices'])) {
            $scores['financial-services'] = ($scores['financial-services'] ?? 0) + 25;
        }
        if (! empty($profile['data_handles_pii']) || ! empty($profile['hires_contractors'])) {
            $scores['compliance-radar'] = ($scores['compliance-radar'] ?? 0) + 20;
        }

        $drain = strtolower((string) ($profile['biggest_time_drain'] ?? ''));
        foreach (self::PAIN_PACK_MAP as $keyword => $packId) {
            if ($drain !== '' && str_contains($drain, $keyword)) {
                $scores[$packId] = ($scores[$packId] ?? 0) + 18;
            }
        }

        $painPoints = $this->decodeJson($profile['pain_points'] ?? null);
        foreach ((array) $painPoints as $pain) {
            $lower = strtolower((string) $pain);
            foreach (self::PAIN_PACK_MAP as $keyword => $packId) {
                if (str_contains($lower, $keyword)) {
                    $scores[$packId] = ($scores[$packId] ?? 0) + 12;
                }
            }
        }

        return $scores;
    }

    /**
     * @return array<string, int>
     */
    private function affinitiesFromSignals(string $tenantId): array
    {
        if (! Schema::hasTable('tenant_pack_signals')) {
            return [];
        }

        $rows = DB::table('tenant_pack_signals')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('pack_id')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $scores = [];
        foreach ($rows as $row) {
            $packId = (string) $row->pack_id;
            $delta = match ($row->signal_type) {
                'pack_install' => 30,
                'pack_view' => 4,
                'route_visit' => 6,
                'atlas_suggested' => 10,
                'feedback_positive' => 15,
                'feedback_negative' => -20,
                default => (int) $row->weight,
            };
            $scores[$packId] = ($scores[$packId] ?? 0) + $delta;
        }

        return $scores;
    }

    /**
     * @param array<string, int> $affinities
     * @param list<string> $installedPackIds
     */
    private function scorePack(string $packId, array $profile, array $affinities, array $installedPackIds): int
    {
        $score = (int) ($affinities[$packId] ?? 0);

        if (in_array($packId, $installedPackIds, true)) {
            $score = max(0, $score - 30);
        }

        return (int) min(100, $score);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function growthReason(string $packId, array $profile, int $score): string
    {
        if ($score < 30) {
            return 'Explore as your business grows';
        }

        $industry = (string) ($profile['industry'] ?? '');
        if ($industry !== '') {
            return 'Fits '.str_replace('_', ' ', $industry).' businesses like yours';
        }

        $drain = (string) ($profile['biggest_time_drain'] ?? '');
        if ($drain !== '') {
            return 'Addresses time lost on: '.Str::limit($drain, 60);
        }

        return 'Based on how you use SpiderNetOS';
    }

    /**
     * @param list<string> $baseOutcomes
     * @return list<string>
     */
    private function personalizedOutcomes(string $packId, string $industry, array $baseOutcomes): array
    {
        $variants = self::OUTCOME_VARIANTS[$packId][$industry] ?? [];
        $merged = array_values(array_unique(array_merge($variants, $baseOutcomes)));

        return array_slice($merged, 0, 4);
    }

    private function absorbIntoProfile(string $tenantId, string $signalType, ?string $packId, array $context, int $weight): void
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return;
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();
        $learned = $this->decodeJson($row->learned_signals ?? null);
        $painPoints = $this->decodeJson($row->pain_points ?? null);

        if ($packId) {
            $affinities = (array) ($learned['pack_affinities'] ?? []);
            $delta = match ($signalType) {
                'pack_install' => 20,
                'feedback_positive' => 12,
                'feedback_negative' => -15,
                'route_visit' => 5,
                'pack_view' => 2,
                default => $weight,
            };
            $affinities[$packId] = max(-50, (int) ($affinities[$packId] ?? 0) + $delta);
            $learned['pack_affinities'] = $affinities;
        }

        if (str_starts_with($signalType, 'feedback_') && ! empty($context['note'])) {
            $painPoints[] = (string) $context['note'];
            $painPoints = array_values(array_unique(array_slice($painPoints, -20)));
        }

        $learned['last_signal'] = [
            'type' => $signalType,
            'pack_id' => $packId,
            'at' => now()->toIso8601String(),
        ];

        if ($row) {
            DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->update([
                'learned_signals' => json_encode($learned),
                'pain_points' => json_encode($painPoints),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('tenant_business_profiles')->insert([
                'tenant_id' => $tenantId,
                'learned_signals' => json_encode($learned),
                'pain_points' => json_encode($painPoints),
                'discovery_complete_pct' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function labelForPack(string $packId): string
    {
        return match ($packId) {
            'financial-services' => 'Set up Financial OS',
            'sales-crm' => 'Grow with Sales & CRM',
            'compliance-radar' => 'Run compliance discovery',
            'real-estate-crm' => 'Open Real Estate CRM',
            default => 'Install '.$packId,
        };
    }

    private function entryPath(string $packId): string
    {
        return match ($packId) {
            'financial-services' => '/financial',
            'sales-crm' => '/sales',
            'compliance-radar' => '/compliance',
            default => '/feature-packs',
        };
    }

    private function normalizeIndustry(string $industry): string
    {
        $normalized = strtolower(trim(str_replace([' ', '-'], '_', $industry)));

        return match (true) {
            str_contains($normalized, 'real') && str_contains($normalized, 'estate') => 'real_estate',
            str_contains($normalized, 'retail') || str_contains($normalized, 'shop') => 'retail',
            str_contains($normalized, 'health') => 'healthcare',
            str_contains($normalized, 'construct') || str_contains($normalized, 'trade') => 'construction',
            str_contains($normalized, 'tech') || str_contains($normalized, 'software') => 'technology',
            str_contains($normalized, 'consult') || str_contains($normalized, 'agency') || str_contains($normalized, 'professional') => 'professional_services',
            default => $normalized !== '' ? $normalized : 'general',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?? [];
        }

        return [];
    }
}
