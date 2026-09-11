<?php

declare(strict_types=1);

namespace App\Services\Outreach\Affonso;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Affonso Finder over its remote MCP server: the shortlist has no REST
 * endpoint, only the tools affonso_list_finder_shortlist and
 * affonso_update_finder_shortlist_item on https://api.affonso.io/mcp
 * (Streamable HTTP + JSON-RPC 2.0, API key as bearer).
 *
 * Streamable HTTP may answer either a JSON body or an SSE stream on the same
 * endpoint, so every response goes through frames(): unverified against the
 * live server until the operator runs `outreach:spike finder`, which is why the
 * Finder sync ships behind its own flag with the CSV import as the fallback.
 */
class AffonsoMcpClient
{
    public const ENDPOINT = 'https://api.affonso.io/mcp';

    public const PROTOCOL_VERSION = '2025-06-18';

    public const LIST_TOOL = 'affonso_list_finder_shortlist';

    public const UPDATE_TOOL = 'affonso_update_finder_shortlist_item';

    private ?string $sessionId = null;

    private int $id = 0;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint = self::ENDPOINT,
    ) {}

    /** @param array<string, mixed> $credentials */
    public static function fromCredentials(array $credentials): self
    {
        $endpoint = trim((string) ($credentials['mcp_endpoint'] ?? ''));

        return new self(trim((string) ($credentials['api_key'] ?? '')), $endpoint !== '' ? $endpoint : self::ENDPOINT);
    }

    /**
     * Handshake, then list the server's tools. The probe the spike runs.
     *
     * @return array{ok: bool, session: ?string, server: array<string, mixed>, tools: list<string>, error: ?string}
     */
    public function handshake(): array
    {
        try {
            $init = $this->rpc('initialize', [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => [],
                'clientInfo' => ['name' => 'spidernetos-outreach', 'version' => '1.0'],
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'session' => null, 'server' => [], 'tools' => [], 'error' => $e->getMessage()];
        }

        // Notifications carry no id and expect no result.
        try {
            $this->notify('notifications/initialized');
            $tools = $this->rpc('tools/list');
        } catch (\Throwable $e) {
            return ['ok' => false, 'session' => $this->sessionId, 'server' => (array) ($init['serverInfo'] ?? []), 'tools' => [], 'error' => $e->getMessage()];
        }

        $names = array_values(array_filter(array_map(
            fn ($tool) => is_array($tool) ? (string) ($tool['name'] ?? '') : '',
            (array) ($tools['tools'] ?? []),
        )));

        return [
            'ok' => true,
            'session' => $this->sessionId,
            'server' => (array) ($init['serverInfo'] ?? []),
            'tools' => $names,
            'error' => null,
        ];
    }

    /**
     * One page of shortlist items, normalised onto ProspectImportService's
     * canonical row keys.
     *
     * @return array{items: list<array<string, mixed>>, raw: array<string, mixed>}
     */
    public function shortlist(array $arguments = []): array
    {
        $result = $this->callTool(self::LIST_TOOL, $arguments);

        return ['items' => array_map([$this, 'toRow'], $this->itemsFrom($result)), 'raw' => $result];
    }

    /** @return array<string, mixed> */
    public function updateItem(string $itemId, string $status): array
    {
        return $this->callTool(self::UPDATE_TOOL, ['id' => $itemId, 'status' => $status]);
    }

    /**
     * Map one Finder item onto the import service's canonical row keys. Finder
     * field names are not contractual, so each one is read from a list of
     * plausible spellings and anything unknown is kept in notes.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function toRow(array $item): array
    {
        $pick = function (array $keys) use ($item) {
            foreach ($keys as $key) {
                $value = $item[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            return null;
        };

        $profile = $pick(['profileUrl', 'profile_url', 'domain', 'url', 'link', 'website']);
        $emails = $item['emails'] ?? ($item['email'] ?? null);

        return [
            'name' => $pick(['name', 'opportunityName', 'opportunity_name', 'title', 'handle']),
            'profile_url' => $profile,
            'primary_url' => $pick(['primaryUrl', 'primary_url', 'postUrl', 'post_url']),
            'all_urls' => $item['allUrls'] ?? ($item['all_urls'] ?? ($item['urls'] ?? [])),
            'category' => $pick(['category', 'source', 'channel']),
            'external_status' => $pick(['status', 'shortlistStatus', 'shortlist_status']),
            'date_added' => $pick(['dateAdded', 'date_added', 'createdAt', 'created_at']),
            'emails' => $emails,
            'notes' => $pick(['notes', 'note', 'description', 'summary']),
            'external_id' => $pick(['id', 'itemId', 'item_id', 'shortlistItemId']),
        ];
    }

    /**
     * Items out of an MCP tool result: structuredContent when the server sends
     * it, otherwise the first text block parsed as JSON.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    public function itemsFrom(array $result): array
    {
        $candidates = [$result['structuredContent'] ?? null, $result];

        foreach ((array) ($result['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $decoded = json_decode((string) ($block['text'] ?? ''), true);
                if (is_array($decoded)) {
                    $candidates[] = $decoded;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            foreach (['items', 'shortlist', 'results', 'data'] as $key) {
                if (isset($candidate[$key]) && is_array($candidate[$key])) {
                    return array_values(array_filter((array) $candidate[$key], 'is_array'));
                }
            }
            if (array_is_list($candidate) && $candidate !== [] && is_array($candidate[0])) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $name, array $arguments = []): array
    {
        if ($this->sessionId === null) {
            $handshake = $this->handshake();
            if (! $handshake['ok']) {
                throw new \RuntimeException('Affonso MCP handshake failed: '.($handshake['error'] ?? 'unknown error'));
            }
        }

        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);

        if (($result['isError'] ?? false) === true) {
            throw new \RuntimeException('Affonso MCP tool '.$name.' failed: '.mb_substr((string) json_encode($result), 0, 300));
        }

        return $result;
    }

    /**
     * One JSON-RPC request/response round trip.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the `result` object
     */
    private function rpc(string $method, array $params = []): array
    {
        $this->id++;
        $response = $this->request()->post('', array_filter([
            'jsonrpc' => '2.0', 'id' => $this->id, 'method' => $method, 'params' => $params !== [] ? $params : null,
        ], fn ($v) => $v !== null));

        $session = $response->header('Mcp-Session-Id');
        if ($session !== '') {
            $this->sessionId = $session;
        }

        if ($response->failed()) {
            throw new \RuntimeException('Affonso MCP '.$method.' HTTP '.$response->status().': '.mb_substr($response->body(), 0, 200));
        }

        foreach ($this->frames($response->body()) as $frame) {
            if (($frame['id'] ?? null) !== $this->id) {
                continue; // a notification or an unrelated stream event
            }
            if (isset($frame['error'])) {
                $error = (array) $frame['error'];
                throw new \RuntimeException('Affonso MCP '.$method.' error '.($error['code'] ?? '?').': '.($error['message'] ?? 'unknown'));
            }

            return (array) ($frame['result'] ?? []);
        }

        throw new \RuntimeException('Affonso MCP '.$method.': no response frame matched request id '.$this->id);
    }

    /** @param array<string, mixed> $params */
    private function notify(string $method, array $params = []): void
    {
        $this->request()->post('', array_filter([
            'jsonrpc' => '2.0', 'method' => $method, 'params' => $params !== [] ? $params : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Split a Streamable-HTTP body into JSON-RPC frames: either one JSON
     * document (object or batch) or SSE `data:` lines.
     *
     * @return list<array<string, mixed>>
     */
    public function frames(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        if (str_starts_with($body, '{') || str_starts_with($body, '[')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return array_is_list($decoded) ? array_values(array_filter($decoded, 'is_array')) : [$decoded];
            }
        }

        $frames = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (! str_starts_with(ltrim($line), 'data:')) {
                continue;
            }
            $decoded = json_decode(trim(substr(ltrim($line), 5)), true);
            if (is_array($decoded)) {
                $frames[] = $decoded;
            }
        }

        return $frames;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->endpoint)
            ->withToken($this->apiKey)
            ->timeout(45)
            ->withHeaders(array_filter([
                'Accept' => 'application/json, text/event-stream',
                'Content-Type' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Session-Id' => $this->sessionId,
            ]));
    }
}
