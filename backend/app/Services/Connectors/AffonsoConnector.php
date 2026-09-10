<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Services\Outreach\Affonso\AffonsoClient;

/**
 * Affonso affiliate program (partner outreach). Credentials: api_key,
 * program_id, optional webhook_secret / portal_subdomain / group_id.
 *
 * The webhook secret is only stored here; it is read by the Affonso webhook
 * signature middleware, not by this connector.
 */
class AffonsoConnector implements ConnectorContract
{
    public function __construct(
        private readonly string $tenantId,
        private readonly array $credentials,
        private readonly array $config = [],
    ) {}

    public function test(): array
    {
        $result = $this->client()->test();

        if ($result['ok'] && empty($this->credentials['webhook_secret'])) {
            $result['detail'] = ((array) ($result['detail'] ?? [])) + [
                'webhook' => 'No signing secret stored: signup webhooks will be rejected until one is added.',
            ];
        }

        return $result;
    }

    public function execute(string $action, array $params = []): array
    {
        return match ($action) {
            'find_affiliate' => $this->findAffiliate($params),
            'create_affiliate' => $this->createAffiliate($params),
            default => ['success' => false, 'error' => "Unsupported Affonso action: {$action}"],
        };
    }

    public function client(): AffonsoClient
    {
        // tenant_integrations.config may carry non-secret defaults (program_id,
        // group_id, base_url); stored credentials always win.
        return AffonsoClient::fromCredentials($this->credentials + $this->config);
    }

    private function findAffiliate(array $params): array
    {
        $email = trim((string) ($params['email'] ?? ''));
        if ($email === '') {
            return ['success' => false, 'error' => 'email is required.'];
        }

        $affiliate = $this->client()->findAffiliateByEmail($email);

        return ['success' => true, 'data' => ['found' => $affiliate !== null, 'affiliate' => $affiliate]];
    }

    private function createAffiliate(array $params): array
    {
        $email = trim((string) ($params['email'] ?? ''));
        if ($email === '') {
            return ['success' => false, 'error' => 'email is required.'];
        }

        $options = array_intersect_key($params, array_flip([
            'program_id', 'tracking_id', 'group_id', 'status', 'external_user_id', 'metadata', 'country_code',
        ]));

        if (empty($options['group_id']) && ! empty($this->credentials['group_id'])) {
            $options['group_id'] = (string) $this->credentials['group_id'];
        }

        // Tag the affiliate with the owning tenant so signup webhooks can be
        // routed back even when external_user_id is absent.
        $options['metadata'] = ((array) ($options['metadata'] ?? [])) + ['spidernet_tenant_id' => $this->tenantId];

        $result = $this->client()->createAffiliate((string) ($params['name'] ?? ''), $email, $options);

        return ['success' => true, 'data' => $result];
    }
}
