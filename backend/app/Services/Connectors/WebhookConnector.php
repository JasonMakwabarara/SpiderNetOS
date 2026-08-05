<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use Illuminate\Support\Facades\Http;

/**
 * Generic outbound webhook — the breadth multiplier. Any tool reachable by
 * Zapier/Make/n8n or a bespoke HTTPS endpoint becomes an integration target
 * without a dedicated adapter.
 *
 * Payloads are signed (HMAC-SHA256 over the raw body, `X-SpiderNet-Signature`)
 * when a signing secret is configured, so receivers can verify authenticity.
 */
class WebhookConnector implements ConnectorContract
{
    public function __construct(
        private readonly string $tenantId,
        private readonly array $credentials,
        private readonly array $config = [],
    ) {}

    public function test(): array
    {
        $url = (string) ($this->credentials['url'] ?? '');
        if (! $this->isSafeUrl($url)) {
            return ['ok' => false, 'error' => 'A valid https:// endpoint URL is required.'];
        }

        $res = $this->post(['type' => 'connection.test', 'tenant_id' => $this->tenantId]);

        return $res['success']
            ? ['ok' => true, 'detail' => ['status' => $res['data']['status'] ?? null]]
            : ['ok' => false, 'error' => $res['error'] ?? 'Endpoint did not accept the test event.'];
    }

    public function execute(string $action, array $params = []): array
    {
        return match ($action) {
            'send_event' => $this->post([
                'type' => (string) ($params['event'] ?? 'event'),
                'tenant_id' => $this->tenantId,
                'data' => $params['data'] ?? [],
                'sent_at' => now()->toIso8601String(),
            ]),
            default => ['success' => false, 'error' => "Unsupported webhook action: {$action}"],
        };
    }

    private function post(array $payload): array
    {
        $url = (string) ($this->credentials['url'] ?? '');
        if (! $this->isSafeUrl($url)) {
            return ['success' => false, 'error' => 'Invalid endpoint URL.'];
        }

        $body = json_encode($payload) ?: '{}';
        $headers = ['Content-Type' => 'application/json'];

        if (! empty($this->credentials['secret'])) {
            $headers['X-SpiderNet-Signature'] = 'sha256='.hash_hmac('sha256', $body, (string) $this->credentials['secret']);
        }

        try {
            $res = Http::withHeaders($headers)->timeout(10)->withBody($body, 'application/json')->post($url);

            return $res->successful()
                ? ['success' => true, 'data' => ['status' => $res->status()]]
                : ['success' => false, 'error' => 'Endpoint returned '.$res->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Only outbound HTTPS to public hosts — blocks SSRF at internal addresses. */
    private function isSafeUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if ($host === '') {
            return false;
        }

        // Reject literal private/reserved IPs; hostnames resolving privately are
        // additionally blocked at egress by infrastructure policy.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return ! in_array(strtolower($host), ['localhost', 'metadata.google.internal'], true);
    }
}
