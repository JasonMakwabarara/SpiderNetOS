<?php

declare(strict_types=1);

namespace App\Services\ZetKai;

use App\Models\TenantIntegration;
use App\Services\Connectors\ConnectorManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Speaks ZetKai's API (plan D7 §4).
 *
 *   GET  /api/sync/changes?since=   notes, categories and links since a cursor
 *   POST /api/notes/query           semantic search over the vault
 *   POST /api/notes                 file a note
 *
 * Text only, never vectors. ZetKai embeds with bge-m3 at 1024 dimensions (or
 * nomic at 768 locally); SpiderNetOS embeds at 384. A vector copied between
 * them is not a worse match, it is a meaningless number — and a stored one
 * would silently poison retrieval rather than fail. So the sync carries prose
 * and SpiderNetOS re-embeds it in its own space.
 */
class ZetKaiClient
{
    public const PROVIDER = 'zetkai';

    /** A query result is cached this long: the same question inside a run is one call. */
    public const QUERY_CACHE_SECONDS = 60;

    /** ZetKai's own page size for sync/changes. */
    public const PAGE_LIMIT = 500;

    public function __construct(private readonly ConnectorManager $connectors) {}

    public function integrationFor(string $tenantId): ?TenantIntegration
    {
        return TenantIntegration::where('tenant_id', $tenantId)
            ->where('provider', self::PROVIDER)
            ->where('is_active', true)
            ->first();
    }

    public function configured(string $tenantId): bool
    {
        $integration = $this->integrationFor($tenantId);
        if ($integration === null) {
            return false;
        }

        $credentials = $this->credentials($integration);

        return trim((string) ($credentials['base_url'] ?? '')) !== ''
            && trim((string) ($credentials['token'] ?? '')) !== '';
    }

    /**
     * Everything that changed since the cursor.
     *
     * @return array{notes: list<array<string, mixed>>, categories: list<array<string, mixed>>, cursor: string|null}
     */
    public function changes(string $tenantId, ?string $since = null): array
    {
        $response = $this->request($tenantId)->get('/api/sync/changes', array_filter([
            'since' => $since,
        ]));

        if ($response->failed()) {
            throw new ZetKaiException('ZetKai refused the sync ('.$response->status().').', $response->status());
        }

        $body = (array) $response->json();
        $changes = (array) ($body['changes'] ?? []);

        return [
            'notes' => array_values(array_filter((array) ($changes['notes'] ?? []), 'is_array')),
            'categories' => array_values(array_filter((array) ($changes['categories'] ?? []), 'is_array')),
            'cursor' => isset($body['cursor']) ? (string) $body['cursor'] : null,
        ];
    }

    /**
     * Semantic search over the vault.
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $tenantId, string $question, int $k = 5): array
    {
        $k = max(1, min(10, $k));
        $key = 'zetkai:query:'.$tenantId.':'.sha1($question.'|'.$k);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request($tenantId)->post('/api/notes/query', ['query' => $question, 'limit' => $k]);

        if ($response->failed()) {
            throw new ZetKaiException('ZetKai refused the query ('.$response->status().').', $response->status());
        }

        $body = (array) $response->json();
        $results = array_values(array_filter((array) ($body['results'] ?? $body['data'] ?? []), 'is_array'));

        Cache::put($key, $results, self::QUERY_CACHE_SECONDS);

        return $results;
    }

    /**
     * File a note into the vault.
     *
     * `client_id` is the idempotency key: the same run filing the same title
     * twice is one note, because a retried job must not fill someone's vault
     * with duplicates.
     *
     * @param  array<string, mixed>  $note
     * @return array<string, mixed>
     */
    public function createNote(string $tenantId, array $note): array
    {
        $response = $this->request($tenantId)->post('/api/notes', $note);

        if ($response->failed()) {
            throw new ZetKaiException(
                'ZetKai refused the note ('.$response->status().'): '
                    .mb_substr((string) ($response->json('message') ?? $response->body()), 0, 200),
                $response->status(),
            );
        }

        return (array) $response->json();
    }

    // ------------------------------------------------------------------ //

    private function request(string $tenantId): PendingRequest
    {
        $integration = $this->integrationFor($tenantId);
        if ($integration === null) {
            throw new ZetKaiException('This workspace is not connected to ZetKai.', 409);
        }

        $credentials = $this->credentials($integration);
        $baseUrl = rtrim((string) ($credentials['base_url'] ?? ''), '/');
        $token = trim((string) ($credentials['token'] ?? ''));

        if ($baseUrl === '' || $token === '') {
            throw new ZetKaiException('The ZetKai connection is missing its base URL or token.', 409);
        }

        return Http::baseUrl($baseUrl)
            ->timeout((int) config('services.zetkai.timeout', 30))
            ->acceptJson()
            ->withToken($token);
    }

    /** @return array<string, mixed> */
    private function credentials(TenantIntegration $integration): array
    {
        try {
            return $this->connectors->credentialsFor($integration);
        } catch (\Throwable $e) {
            Log::warning('zetkai.credentials_unreadable', ['tenant_id' => $integration->tenant_id, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
