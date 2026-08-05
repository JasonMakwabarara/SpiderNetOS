<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use Illuminate\Support\Facades\Http;

/**
 * Generic REST caller for in-house or unsupported tools: a base URL plus an
 * optional bearer token. Agents invoke `request` with method/path/body.
 */
class HttpApiConnector implements ConnectorContract
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly string $tenantId,
        private readonly array $credentials,
        private readonly array $config = [],
    ) {}

    public function test(): array
    {
        $base = rtrim((string) ($this->credentials['base_url'] ?? ''), '/');
        if (! str_starts_with($base, 'https://')) {
            return ['ok' => false, 'error' => 'A https:// base URL is required.'];
        }

        $res = $this->execute('request', ['method' => 'GET', 'path' => (string) ($this->config['health_path'] ?? '/')]);

        return $res['success']
            ? ['ok' => true, 'detail' => ['status' => $res['data']['status'] ?? null]]
            : ['ok' => false, 'error' => $res['error'] ?? 'Base URL did not respond successfully.'];
    }

    public function execute(string $action, array $params = []): array
    {
        if ($action !== 'request') {
            return ['success' => false, 'error' => "Unsupported action: {$action}"];
        }

        $base = rtrim((string) ($this->credentials['base_url'] ?? ''), '/');
        if (! str_starts_with($base, 'https://')) {
            return ['success' => false, 'error' => 'Invalid base URL.'];
        }

        $method = strtoupper((string) ($params['method'] ?? 'GET'));
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            return ['success' => false, 'error' => "Unsupported method: {$method}"];
        }

        $path = '/'.ltrim((string) ($params['path'] ?? '/'), '/');

        try {
            $req = Http::timeout(15)->acceptJson();
            if (! empty($this->credentials['token'])) {
                $req = $req->withToken((string) $this->credentials['token']);
            }

            $res = $req->send($method, $base.$path, [
                'json' => $params['body'] ?? null,
                'query' => $params['query'] ?? null,
            ]);

            return $res->successful()
                ? ['success' => true, 'data' => ['status' => $res->status(), 'body' => $res->json() ?? $res->body()]]
                : ['success' => false, 'error' => 'API returned '.$res->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
