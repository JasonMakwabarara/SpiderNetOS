<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP gateway to the OpenJarvis bridge microservice.
 *
 * @see https://github.com/open-jarvis/OpenJarvis
 */
class OpenJarvisGateway
{
    public function baseUrl(): string
    {
        return rtrim((string) config('services.openjarvis.url'), '/');
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.openjarvis.enabled', true);
    }

    public function health(): array
    {
        if (!$this->isEnabled()) {
            return ['status' => 'disabled'];
        }

        try {
            $response = Http::timeout(5)->get($this->baseUrl().'/api/health');

            return $response->successful()
                ? $response->json()
                : ['status' => 'unreachable', 'code' => $response->status()];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function listAgents(): array
    {
        return $this->getJson('/v1/agents')['data'] ?? [];
    }

    public function listSkills(): array
    {
        return $this->getJson('/v1/skills')['data'] ?? [];
    }

    /**
     * @param  array{message: string, agent?: string, tenant_id?: string, session_id?: string, context?: array, skills?: array}  $payload
     */
    public function ask(array $payload): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }

        try {
            $response = Http::timeout((int) config('services.openjarvis.timeout', 60))
                ->retry(1, 300)
                ->post($this->baseUrl().'/v1/ask', $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('OpenJarvisGateway::ask failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('OpenJarvisGateway::ask exception', ['error' => $e->getMessage()]);
        }

        return ['ok' => false, 'reason' => 'unavailable'];
    }

    public function research(string $query, ?string $tenantId = null, int $maxHops = 3): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }

        try {
            $response = Http::timeout((int) config('services.openjarvis.timeout', 90))
                ->post($this->baseUrl().'/v1/research', [
                    'query' => $query,
                    'tenant_id' => $tenantId,
                    'max_hops' => $maxHops,
                ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            Log::error('OpenJarvisGateway::research exception', ['error' => $e->getMessage()]);
        }

        return ['ok' => false, 'reason' => 'unavailable'];
    }

    private function getJson(string $path): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        try {
            $response = Http::timeout(10)->get($this->baseUrl().$path);

            return $response->successful() ? ($response->json() ?? []) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
