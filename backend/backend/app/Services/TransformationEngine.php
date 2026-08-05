<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TransformationEngine
 *
 * Core B2 pipeline that converts parsed intent + execution_result into a
 * 5-field Atlas contract. Atlas thinks in before -> after transformations,
 * not in tasks.
 *
 * Execution order:
 *   1. infer_desired_outcome(intent)
 *   2. identify_pain(intent)
 *   3. calculate_value(outcome, execution_result)
 *   4. construct_response(future_state, value, emotional_shift, action_summary)
 *
 * When the inference plane is reachable, responses are generated via LLM
 * using AtlasPromptStack. When unavailable, a deterministic fallback builds
 * a safe contract-compliant response from templates.
 */
class TransformationEngine
{
    public function __construct(
        private readonly AtlasPromptStack $prompts,
        private readonly AtlasResponseFormatter $formatter,
    ) {
    }

    /**
     * @param array $parsedIntent  desired_future, pain_points, functional_goal, emotional_goal, task_type
     * @param array $executionResult  status, metrics, agent_used, etc
     * @param string $style  concise|balanced|emotional|analytical|directive
     */
    public function transform(
        array $parsedIntent,
        array $executionResult,
        string $style = 'balanced'
    ): array {
        // Try LLM-backed construction when inference plane is available
        $llmResponse = $this->generateViaLLM($parsedIntent, $executionResult, $style);

        if ($llmResponse !== null) {
            $contract = $this->formatter->buildContractPayload($llmResponse);
            $validation = $this->formatter->validate(
                $contract,
                $this->extractExecutionMetrics($executionResult)
            );

            if ($validation['ok']) {
                return [
                    'contract' => $contract,
                    'source'   => 'llm',
                    'violations' => [],
                    'style' => $style,
                ];
            }

            Log::info('TransformationEngine: LLM response failed validation, using deterministic build', [
                'violations' => $validation['violations'],
            ]);
        }

        // Deterministic fallback
        $contract = $this->buildDeterministic($parsedIntent, $executionResult);
        $validation = $this->formatter->validate(
            $contract,
            $this->extractExecutionMetrics($executionResult)
        );

        return [
            'contract' => $validation['ok']
                ? $contract
                : $this->formatter->fallback('', $executionResult),
            'source' => 'deterministic',
            'violations' => $validation['violations'],
            'style' => $style,
        ];
    }

    /**
     * Deterministic contract builder that never calls the inference plane.
     * Used as fallback and as the v1 safe path.
     */
    private function buildDeterministic(array $intent, array $result): array
    {
        $desired = trim((string) ($intent['desired_future'] ?? ''));
        $pain = trim((string) ($intent['pain_points'] ?? ''));
        $taskType = (string) ($intent['task_type'] ?? 'chat');

        $metrics = $this->extractExecutionMetrics($result);

        $futureState = $desired !== ''
            ? "You now have: {$desired}."
            : match ($taskType) {
                'automation' => 'Your work now runs automatically in the background.',
                'analysis' => 'You now have clarity on what matters most.',
                'creation' => 'Your new asset is ready and in place.',
                'monitoring' => 'You now have full visibility across your systems.',
                default => 'Your request is moving forward with clarity.',
            };

        $value = $this->buildValueStatement($metrics, $taskType);

        $emotionalShift = $pain !== ''
            ? "No more {$pain}."
            : match ($taskType) {
                'automation' => 'No more repetitive manual steps.',
                'analysis' => 'No more second-guessing your priorities.',
                'monitoring' => 'Nothing slips through the cracks.',
                default => 'Less friction, more focus.',
            };

        $actionSummary = match ($taskType) {
            'automation' => 'I set up the automation and scheduled it for you.',
            'analysis' => 'I analyzed your data and surfaced the key insight.',
            'creation' => 'I built the asset and saved it to your workspace.',
            'monitoring' => 'I enabled continuous monitoring with alerts.',
            default => 'I handled your request end-to-end.',
        };

        return [
            'future_state'    => $futureState,
            'value'           => $value,
            'emotional_shift' => $emotionalShift,
            'action_summary'  => $actionSummary,
            'details'         => null,
        ];
    }

    private function buildValueStatement(array $metrics, string $taskType): string
    {
        if (!empty($metrics['time_saved_hours'])) {
            $hours = (float) $metrics['time_saved_hours'];
            return sprintf("You reclaim roughly %s hours of effort.", $this->prettyNumber($hours));
        }

        if (!empty($metrics['cost_automated_usd'])) {
            $cost = (float) $metrics['cost_automated_usd'];
            return sprintf("You automated around \$%s of recurring work.", $this->prettyNumber($cost));
        }

        if (!empty($metrics['tasks_automated'])) {
            $n = (int) $metrics['tasks_automated'];
            return sprintf("You removed %d recurring tasks from your plate.", $n);
        }

        return match ($taskType) {
            'automation' => 'You free up ongoing time that compounds week over week.',
            'analysis' => 'You have a clear next move, not more data to parse.',
            default => 'You move forward with less effort than before.',
        };
    }

    private function extractExecutionMetrics(array $executionResult): array
    {
        $metrics = $executionResult['metrics'] ?? [];

        return [
            'time_saved_hours'   => $metrics['time_saved_hours']   ?? $executionResult['time_saved_hours']   ?? null,
            'cost_automated_usd' => $metrics['cost_automated_usd'] ?? $executionResult['cost_automated_usd'] ?? null,
            'tasks_automated'    => $metrics['tasks_automated']    ?? $executionResult['tasks_automated']    ?? null,
        ];
    }

    private function prettyNumber(float $n): string
    {
        if ($n >= 100) {
            return (string) (int) round($n);
        }
        if ($n >= 10) {
            return number_format($n, 1);
        }
        return number_format($n, 2);
    }

    /**
     * Attempt LLM-backed response generation via the inference plane.
     * Returns null if inference is unavailable, times out, or returns invalid JSON.
     */
    private function generateViaLLM(array $intent, array $executionResult, string $style): ?array
    {
        $inferenceUrl = (string) config('services.inference.url', '');
        if ($inferenceUrl === '') {
            return null;
        }

        try {
            $prompt = $this->prompts->responseConstructionPrompt($intent, $executionResult, $style);
            $response = Http::timeout(8)->post(rtrim($inferenceUrl, '/') . '/generate', [
                'prompt'        => $prompt,
                'system_prompt' => $this->prompts->systemPrompt(),
                'model'         => (string) config('services.spidernet.prompt_enhancer_model', 'gemma4'),
                'temperature'   => 0.3,
                'max_tokens'    => 500,
                'tenant_id'     => 'system',
                'cost_ceiling'  => 0.10,
                'tenant_tier'   => 'growth',
            ]);

            if (!$response->successful()) {
                return null;
            }

            $text = (string) ($response->json('text') ?? '');
            $decoded = $this->extractJson($text);
            if (!is_array($decoded)) {
                return null;
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::info('TransformationEngine: LLM call failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract the first JSON object from a text blob.
     */
    private function extractJson(string $text): mixed
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
