<?php

namespace App\Services;

/**
 * TransformationScore
 *
 * B6 — computes the scalar Transformation Score (TS) for an Atlas interaction
 * row. TS is the core reward signal for future RL/DPO work, and a real-time
 * quality KPI for operators.
 *
 * Formula:
 *   TS = w1*ValuePerception + w2*Clarity + w3*EmotionalImpact
 *      + w4*Actionability   + w5*Trust
 *      - w6*CognitiveLoad   - w7*TechnicalLeakage
 *
 * Default weights (config-driven via services.spidernet.ts_weights):
 *   w1=0.25  w2=0.15  w3=0.20  w4=0.15  w5=0.15  w6=0.05  w7=0.05
 *
 * All sub-scores are in [0.0, 1.0]. TS is clamped to [0.0, 1.0] at the end.
 */
class TransformationScore
{
    /** Relief/gain words that imply emotional resonance. */
    private const RELIEF_WORDS = [
        'no more', 'handled', 'automatic', 'automatically',
        'you now', 'you have', "you've", 'you will',
        'unlocked', 'freed up', 'ready', 'clarity', 'control',
        'in place', 'relief', 'confidence',
    ];

    /** Technical terms that leak system internals into user-facing output. */
    private const TECHNICAL_TERMS = [
        'DAG', 'dag', 'node', 'nodes', 'pipeline', 'pipelines',
        'API', 'endpoint', 'endpoints', 'payload', 'webhook',
        'database', 'schema', 'sql', 'redis', 'kafka',
        'docker', 'container', 'kubernetes',
    ];

    /**
     * Compute TS + sub-scores from an atlas_interactions row (decoded).
     *
     * @param array $row  {
     *   atlas_response: {future_state, value, emotional_shift, action_summary, details?},
     *   execution_result: array,
     *   parsed_intent: array,
     *   clicked_expand?: bool,
     *   accepted_action?: bool,
     *   follow_up?: bool,
     *   time_on_response_ms?: ?int,
     *   rating?: ?int
     * }
     * @return array{
     *   final_ts: float,
     *   value_perception_score: float,
     *   clarity_score: float,
     *   emotional_score: float,
     *   actionability_score: float,
     *   trust_score: float,
     *   cognitive_load_penalty: float,
     *   technical_leakage_penalty: float
     * }
     */
    public function compute(array $row): array
    {
        $response = (array) ($row['atlas_response'] ?? []);
        $visible = $this->visibleText($response);

        $valuePerception = $this->valuePerceptionScore($response);
        $clarity         = $this->clarityScore($visible);
        $emotional       = $this->emotionalScore($response);
        $actionability   = $this->actionabilityScore($row);
        $trust           = $this->trustScore($response, (array) ($row['execution_result'] ?? []));
        $cognitiveLoad   = $this->cognitiveLoadPenalty($visible);
        $techLeakage     = $this->technicalLeakagePenalty($visible);

        $weights = $this->weights();

        $ts =
            $weights['value']        * $valuePerception
            + $weights['clarity']      * $clarity
            + $weights['emotional']    * $emotional
            + $weights['actionability']* $actionability
            + $weights['trust']        * $trust
            - $weights['cognitive']    * $cognitiveLoad
            - $weights['leakage']      * $techLeakage;

        $ts = max(0.0, min(1.0, $ts));

        return [
            'final_ts' => round($ts, 4),
            'value_perception_score'    => round($valuePerception, 4),
            'clarity_score'             => round($clarity, 4),
            'emotional_score'           => round($emotional, 4),
            'actionability_score'       => round($actionability, 4),
            'trust_score'               => round($trust, 4),
            'cognitive_load_penalty'    => round($cognitiveLoad, 4),
            'technical_leakage_penalty' => round($techLeakage, 4),
        ];
    }

    // ---------------------------------------------------------------------
    // Sub-scores
    // ---------------------------------------------------------------------

    private function valuePerceptionScore(array $response): float
    {
        $value = (string) ($response['value'] ?? '');
        if ($value === '') {
            return 0.0;
        }
        $score = 0.5; // base for having a value statement at all

        if (preg_match('/\d/', $value)) {
            $score += 0.3; // quantified
        }

        if (preg_match('/\b(you|your)\b/i', $value)) {
            $score += 0.2;
        }

        return min(1.0, $score);
    }

    private function clarityScore(string $visible): float
    {
        if ($visible === '') {
            return 0.0;
        }
        // Favor short, direct sentences. Penalize overly long structure.
        $sentences = preg_split('/[\.\!\?]+\s+/', $visible, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($sentences);
        if ($count === 0) {
            return 0.3;
        }

        $avgWords = 0;
        foreach ($sentences as $s) {
            $avgWords += str_word_count($s);
        }
        $avgWords = $avgWords / $count;

        if ($avgWords <= 14) return 1.0;
        if ($avgWords <= 22) return 0.8;
        if ($avgWords <= 30) return 0.6;
        if ($avgWords <= 40) return 0.4;
        return 0.2;
    }

    private function emotionalScore(array $response): float
    {
        $shift = (string) ($response['emotional_shift'] ?? '');
        if ($shift === '') {
            return 0.0;
        }

        $hits = 0;
        $lower = strtolower($shift);
        foreach (self::RELIEF_WORDS as $word) {
            if (str_contains($lower, $word)) {
                $hits++;
            }
        }

        return min(1.0, 0.3 + (0.2 * $hits));
    }

    private function actionabilityScore(array $row): float
    {
        $score = 0.4; // baseline when action_summary exists
        if (!empty($row['accepted_action'])) $score += 0.35;
        if (!empty($row['follow_up'])) $score += 0.15;
        if (!empty($row['clicked_expand'])) $score += 0.10;
        return min(1.0, $score);
    }

    private function trustScore(array $response, array $executionResult): float
    {
        // Start high; deduct for overpromise signals without metric backing.
        $score = 0.9;
        $value = (string) ($response['value'] ?? '');

        preg_match_all('/(\d+(?:\.\d+)?)/', $value, $m);
        $claims = array_map('floatval', $m[1] ?? []);
        if (empty($claims)) {
            return $score;
        }

        $metrics = (array) ($executionResult['metrics'] ?? []);
        $maxMetric = 0.0;
        foreach (['time_saved_hours','cost_automated_usd','tasks_automated'] as $k) {
            if (isset($metrics[$k])) {
                $maxMetric = max($maxMetric, (float) $metrics[$k]);
            }
        }

        // If claims exist but no metrics back them, deduct
        if ($maxMetric <= 0.0 && max($claims) > 0) {
            $score -= 0.3;
        } elseif ($maxMetric > 0 && max($claims) > $maxMetric * 1.5) {
            $score -= 0.4; // likely overpromise
        }

        return max(0.0, $score);
    }

    private function cognitiveLoadPenalty(string $visible): float
    {
        $words = str_word_count($visible);
        if ($words <= 50) return 0.0;
        if ($words <= 100) return 0.2;
        if ($words <= 200) return 0.5;
        return 0.9;
    }

    private function technicalLeakagePenalty(string $visible): float
    {
        $hits = 0;
        $lower = strtolower($visible);
        foreach (self::TECHNICAL_TERMS as $term) {
            if (preg_match('/\b' . preg_quote(strtolower($term), '/') . '\b/', $lower)) {
                $hits++;
            }
        }
        return min(1.0, 0.3 * $hits);
    }

    private function visibleText(array $response): string
    {
        return trim(implode(' ', [
            (string) ($response['future_state']    ?? ''),
            (string) ($response['value']           ?? ''),
            (string) ($response['emotional_shift'] ?? ''),
            (string) ($response['action_summary']  ?? ''),
        ]));
    }

    private function weights(): array
    {
        $defaults = [
            'value' => 0.25,
            'clarity' => 0.15,
            'emotional' => 0.20,
            'actionability' => 0.15,
            'trust' => 0.15,
            'cognitive' => 0.05,
            'leakage' => 0.05,
        ];

        $configured = (array) config('services.spidernet.ts_weights', []);
        return array_merge($defaults, $configured);
    }
}
