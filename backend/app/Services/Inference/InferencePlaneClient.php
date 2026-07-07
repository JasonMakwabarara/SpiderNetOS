<?php

declare(strict_types=1);

namespace App\Services\Inference;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the inference plane (inference/main.py — POST /generate).
 *
 * The plane does its own policy routing (cost ceiling, latency, tenant tier,
 * provider fallback chains); this client only speaks the request contract
 * and normalizes the response.
 */
class InferencePlaneClient
{
    /**
     * @return array{text: string, model: string, tokens_used: int, cost: float, provider: string}
     */
    public function generate(
        string $prompt,
        ?string $systemPrompt = null,
        string $tenantTier = 'starter',
        float $costCeiling = 0.50,
        int $maxTokens = 2048,
    ): array {
        $response = Http::baseUrl($this->baseUrl())
            ->timeout($this->timeoutSeconds())
            ->acceptJson()
            ->post('/generate', [
                'prompt' => $prompt,
                'system_prompt' => $systemPrompt,
                'tenant_tier' => $tenantTier,
                'cost_ceiling' => $costCeiling,
                'max_tokens' => $maxTokens,
                'temperature' => 0.2, // runbook steps want precision, not creativity
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Inference plane error (' . $response->status() . '): ' . mb_substr($response->body(), 0, 300)
            );
        }

        $text = $response->json('text');

        if (! is_string($text) || trim($text) === '') {
            throw new \RuntimeException('Inference plane returned an empty completion.');
        }

        return [
            'text' => $text,
            'model' => (string) $response->json('model', 'unknown'),
            'tokens_used' => (int) $response->json('tokens_used', 0),
            'cost' => (float) $response->json('cost', 0.0),
            'provider' => (string) $response->json('provider', 'unknown'),
        ];
    }

    public function configured(): bool
    {
        return trim((string) config('services.inference.url', '')) !== '';
    }

    private function baseUrl(): string
    {
        return (string) config('services.inference.url', 'http://localhost:9000');
    }

    private function timeoutSeconds(): int
    {
        return (int) config('services.inference.timeout', 60);
    }
}
