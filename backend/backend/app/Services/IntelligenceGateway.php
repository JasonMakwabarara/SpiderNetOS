<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bridges V1 Laravel business logic to the V2 intelligence layer (semantic gateway).
 */
class IntelligenceGateway
{
    protected string $baseUrl;

    protected string $dagCompilerUrl;

    protected string $runtimeGuardianUrl;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.intelligence_gateway.url'), '/');
        $this->dagCompilerUrl = rtrim(config('services.dag_compiler.url'), '/');
        $this->runtimeGuardianUrl = rtrim(config('services.runtime_guardian.url'), '/');
        $this->timeout = (int) config('services.intelligence_gateway.timeout', 30);
    }

    public function evaluate(array $eventPayload, string $workspaceId): array
    {
        $url = "{$this->baseUrl}/v2/gateway/evaluate";

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->withQueryParameters([
                    'workspace_id' => $workspaceId,
                    'event_payload' => json_encode($eventPayload, JSON_THROW_ON_ERROR),
                ])
                ->post($url);

            if ($response->failed()) {
                Log::warning('IntelligenceGateway evaluate failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'error' => $response->json('detail') ?? $response->body(),
                ];
            }

            return array_merge(['ok' => true], $response->json());
        } catch (\Throwable $e) {
            Log::error('IntelligenceGateway evaluate exception', ['url' => $url, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function coordinateAtlasCycle(string $workspaceId, ?string $listId = null): array
    {
        $listId ??= '11111111-1111-1111-1111-111111111111';
        $url = "{$this->baseUrl}/v2/atlas/coordinate-cycle";

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->withQueryParameters(['workspace_id' => $workspaceId])
                ->post($url);

            if ($response->failed()) {
                Log::warning('IntelligenceGateway coordinate cycle failed', [
                    'url' => $url,
                    'list_id' => $listId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'error' => $response->json('detail') ?? $response->body(),
                ];
            }

            return array_merge(['ok' => true, 'list_id' => $listId], $response->json());
        } catch (\Throwable $e) {
            Log::error('IntelligenceGateway coordinate cycle exception', ['url' => $url, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function compileDag(array $dag): array
    {
        $url = "{$this->dagCompilerUrl}/validate";

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->post($url, $dag);

            if ($response->failed()) {
                Log::warning('IntelligenceGateway compile failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'error' => $response->json('detail') ?? $response->body(),
                ];
            }

            return array_merge(['ok' => true], $response->json());
        } catch (\Throwable $e) {
            Log::error('IntelligenceGateway compile exception', ['url' => $url, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function auditPlaybook(string $workspaceId, string $playbookId): array
    {
        return $this->postTo($this->runtimeGuardianUrl.'/audit', [
            'workspace_id' => $workspaceId,
            'playbook_id' => $playbookId,
        ]);
    }

    public function health(): array
    {
        foreach (['/api/health', '/health'] as $path) {
            try {
                $response = Http::timeout(5)->get($this->baseUrl.$path);
                if ($response->successful()) {
                    $body = $response->json() ?? [];
                    $status = $body['status'] ?? 'healthy';

                    return [
                        'status' => in_array($status, ['ok', 'healthy'], true) ? 'healthy' : $status,
                        'endpoint' => $path,
                        'details' => $body,
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug('IntelligenceGateway health probe failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        return ['status' => 'unreachable', 'error' => 'semantic gateway health check failed'];
    }

    /** @return array<int, array<string, mixed>> */
    public function listRecommendations(string $workspaceId): array
    {
        return $this->normalizeList(
            $this->getJson("{$this->baseUrl}/api/recommendations")
        );
    }

    public function acceptRecommendation(string $id): array
    {
        return $this->patchJson("{$this->baseUrl}/api/recommendations/{$id}/accept");
    }

    public function rejectRecommendation(string $id): array
    {
        return $this->patchJson("{$this->baseUrl}/api/recommendations/{$id}/reject");
    }

    public function getAutonomy(string $workspaceId): array
    {
        $result = $this->getJson("{$this->baseUrl}/api/autonomy/settings/{$workspaceId}");

        if (!is_array($result) || ($result['ok'] ?? true) === false) {
            return [
                'autonomy_level' => 1,
                'auto_execute_threshold' => 0.85,
                'rollback_threshold' => 0.05,
            ];
        }

        unset($result['ok']);

        return $result;
    }

    public function updateAutonomy(string $workspaceId, array $settings): array
    {
        return $this->putJson("{$this->baseUrl}/api/autonomy/settings/{$workspaceId}", $settings);
    }

    protected function getJson(string $url): mixed
    {
        try {
            $response = Http::timeout($this->timeout)->acceptJson()->get($url);
            if ($response->failed()) {
                return ['ok' => false, 'error' => $response->body()];
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('IntelligenceGateway GET failed', ['url' => $url, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<int, array<string, mixed>> */
    protected function normalizeList(mixed $result): array
    {
        if (!is_array($result) || ($result['ok'] ?? true) === false) {
            return [];
        }

        return array_is_list($result) ? $result : [];
    }

    protected function patchJson(string $url): array
    {
        try {
            $response = Http::timeout($this->timeout)->acceptJson()->patch($url);
            if ($response->failed()) {
                return ['ok' => false, 'error' => $response->body()];
            }

            return array_merge(['ok' => true], $response->json() ?? []);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function putJson(string $url, array $payload): array
    {
        try {
            $response = Http::timeout($this->timeout)->acceptJson()->put($url, $payload);
            if ($response->failed()) {
                return ['ok' => false, 'error' => $response->body()];
            }

            return array_merge(['ok' => true], $response->json() ?? []);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function post(string $path, array $payload): array
    {
        return $this->postTo($this->baseUrl.$path, $payload);
    }

    protected function postTo(string $url, array $payload): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->failed()) {
                Log::warning('IntelligenceGateway request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'error' => $response->json('detail') ?? $response->body(),
                ];
            }

            return array_merge(['ok' => true], $response->json());
        } catch (\Throwable $e) {
            Log::error('IntelligenceGateway exception', ['url' => $url, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
