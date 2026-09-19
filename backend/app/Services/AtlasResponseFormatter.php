<?php

namespace App\Services;

/**
 * AtlasResponseFormatter
 *
 * Enforces the Atlas 5-field cognitive contract:
 *   - future_state
 *   - value
 *   - emotional_shift
 *   - action_summary
 *   - details (optional)
 *
 * Implements B4 guardrails:
 *   - Forbidden technical-jargon filter (DAG, node, pipeline, API...)
 *   - Anti-hype filter (massive, revolutionary, guaranteed...)
 *   - Truth anchoring: quantified value_claims cannot exceed
 *     execution_result metric * tolerance.
 *
 * When validation fails, validate() returns a list of violations; the caller
 * is expected to either rewrite or fall back to a safe template.
 */
class AtlasResponseFormatter
{
    /** Forbidden technical terms unless `details` was explicitly requested. */
    private const FORBIDDEN_TERMS = [
        'DAG', 'dag', 'node', 'nodes', 'pipeline', 'pipelines',
        'API', 'endpoint', 'endpoints', 'payload', 'webhook',
        'database', 'schema', 'sql', 'redis', 'kafka',
        'docker', 'container', 'kubernetes',
    ];

    /** Anti-hype terms that inflate perceived value beyond honesty. */
    private const HYPE_TERMS = [
        'massive', 'revolutionary', 'guaranteed',
        'amazing', 'incredible', 'unbelievable',
        'world-class', 'best-in-class', 'cutting-edge', 'bleeding-edge',
        'seamless', 'effortless',
    ];

    public function buildContractPayload(array $fields): array
    {
        return [
            'future_state' => (string) ($fields['future_state'] ?? ''),
            'value' => (string) ($fields['value'] ?? ''),
            'emotional_shift' => (string) ($fields['emotional_shift'] ?? ''),
            'action_summary' => (string) ($fields['action_summary'] ?? ''),
            'details' => isset($fields['details']) && $fields['details'] !== ''
                ? (string) $fields['details']
                : null,
        ];
    }

    /**
     * Validate a response against the cognitive contract and guardrails.
     *
     * @param  array  $response  5-field contract payload
     * @param  array  $executionMetrics  actual execution metrics for truth anchoring
     * @param  bool  $allowTechnicalInDetails  if true, `details` can contain technical terms
     * @return array{ok: bool, violations: array<int,string>}
     */
    public function validate(array $response, array $executionMetrics = [], bool $allowTechnicalInDetails = true): array
    {
        $violations = [];

        // Required structure
        foreach (['future_state', 'value', 'emotional_shift', 'action_summary'] as $key) {
            if (empty($response[$key]) || ! is_string($response[$key])) {
                $violations[] = "missing_or_empty_field:{$key}";
            }
        }

        if (! empty($violations)) {
            return ['ok' => false, 'violations' => $violations];
        }

        // Visible (non-details) surface combined for scanning
        $visibleText = implode(' ', [
            $response['future_state'],
            $response['value'],
            $response['emotional_shift'],
            $response['action_summary'],
        ]);

        // Forbidden jargon in visible fields
        $jargonHits = $this->scanTerms($visibleText, self::FORBIDDEN_TERMS);
        foreach ($jargonHits as $term) {
            $violations[] = "forbidden_term_in_visible:{$term}";
        }

        // Hype words in any surface (including details)
        $allText = $visibleText.' '.($response['details'] ?? '');
        $hypeHits = $this->scanTerms($allText, self::HYPE_TERMS);
        foreach ($hypeHits as $term) {
            $violations[] = "hype_term:{$term}";
        }

        // If details contains technical terms and that's disallowed, flag
        if (! $allowTechnicalInDetails && ! empty($response['details'])) {
            $detailsHits = $this->scanTerms($response['details'], self::FORBIDDEN_TERMS);
            foreach ($detailsHits as $term) {
                $violations[] = "forbidden_term_in_details:{$term}";
            }
        }

        // Truth anchor: extract numeric claims from `value` and bound them against execution_metrics.
        $anchorViolations = $this->truthAnchor($response['value'], $executionMetrics);
        $violations = array_merge($violations, $anchorViolations);

        return [
            'ok' => empty($violations),
            'violations' => $violations,
        ];
    }

    /**
     * Safe fallback response when generation or validation fails hard.
     */
    public function fallback(string $userMessage = '', array $executionResult = []): array
    {
        $status = $executionResult['status'] ?? 'received';

        return [
            'future_state' => 'Your request is in motion and ready to move forward.',
            'value' => 'You have clarity on the next step.',
            'emotional_shift' => 'Less friction, more focus.',
            'action_summary' => 'I received your request and prepared the next step.',
            'details' => null,
        ];
    }

    /**
     * Returns list of forbidden terms present in the given text.
     */
    private function scanTerms(string $text, array $terms): array
    {
        $hits = [];
        $lower = strtolower($text);
        foreach ($terms as $term) {
            $pattern = '/\b'.preg_quote(strtolower($term), '/').'\b/';
            if (preg_match($pattern, $lower)) {
                $hits[] = strtolower($term);
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * Truth-anchor: prevent overpromising beyond actual execution.
     *
     * Recognized anchors in execution_metrics (all optional, floats):
     *   - time_saved_hours
     *   - cost_automated_usd
     *   - tasks_automated
     *
     * Tolerance factor is configurable via services.spidernet.truth_anchor_tolerance.
     */
    private function truthAnchor(string $valueText, array $metrics): array
    {
        $violations = [];
        $tolerance = (float) config('services.spidernet.truth_anchor_tolerance', 1.25);

        // Extract numeric tokens from text
        if (! preg_match_all('/(\d+(?:\.\d+)?)/', $valueText, $matches)) {
            return [];
        }

        $numbers = array_map('floatval', $matches[1]);
        if (empty($numbers)) {
            return [];
        }

        $maxClaimed = max($numbers);

        // Build upper bound from known metrics
        $upperBound = 0.0;
        foreach (['time_saved_hours', 'cost_automated_usd', 'tasks_automated'] as $key) {
            if (isset($metrics[$key]) && is_numeric($metrics[$key])) {
                $upperBound = max($upperBound, (float) $metrics[$key] * $tolerance);
            }
        }

        // If no execution metrics exist, we can't anchor; leave as informational pass
        if ($upperBound <= 0) {
            return [];
        }

        if ($maxClaimed > $upperBound) {
            $violations[] = sprintf(
                'truth_anchor_exceeded:claimed=%.2f>bound=%.2f',
                $maxClaimed,
                $upperBound
            );
        }

        return $violations;
    }
}
