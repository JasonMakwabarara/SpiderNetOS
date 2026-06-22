<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Universal SME compliance discovery — surfaces obligations from business profile.
 */
class ComplianceRadar
{
    /**
     * @param array<string, mixed> $profile
     * @return array<int, array<string, mixed>>
     */
    public function obligationsForProfile(array $profile): array
    {
        $obligations = [];

        if (! empty($profile['data_handles_pii'])) {
            $obligations[] = [
                'id' => 'privacy_basics',
                'title' => 'Customer data & privacy',
                'summary' => 'You handle personal data — keep a simple record of what you collect and why.',
                'severity' => 'action_needed',
                'action' => 'Answer three privacy questions in Atlas',
                'action_path' => '/atlas',
            ];
        }

        if (! empty($profile['issues_invoices'])) {
            $obligations[] = [
                'id' => 'invoice_tax_basics',
                'title' => 'Invoice & tax records',
                'summary' => 'Keep invoices and payment records organised for tax time and audits.',
                'severity' => 'awareness',
                'action' => 'Enable Financial OS',
                'action_path' => '/feature-packs',
            ];
        }

        $band = $profile['employee_count_band'] ?? null;
        if ($band && $band !== 'solo') {
            $obligations[] = [
                'id' => 'employment_basics',
                'title' => 'Team & employment records',
                'summary' => 'With employees or contractors, basic HR records and contracts matter.',
                'severity' => 'awareness',
                'action' => 'Tell Atlas about your team structure',
                'action_path' => '/atlas',
            ];
        }

        if (! empty($profile['hires_contractors'])) {
            $obligations[] = [
                'id' => 'contractor_basics',
                'title' => 'Contractor agreements',
                'summary' => 'Track who you hire, scope of work, and payment terms.',
                'severity' => 'action_needed',
                'action' => 'Set up contractor tracking',
                'action_path' => '/compliance',
            ];
        }

        if (empty($obligations)) {
            $obligations[] = [
                'id' => 'discovery_start',
                'title' => 'Let SpiderNetOS learn your business',
                'summary' => 'Answer a few questions and we will show what compliance topics apply to you.',
                'severity' => 'awareness',
                'action' => 'Start with Atlas',
                'action_path' => '/atlas?seed=discovery',
            ];
        }

        return $obligations;
    }
}
