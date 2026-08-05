<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Models\TenantIntegration;
use App\Services\Integrations\CalendarAdapter;
use App\Services\Integrations\CrmAdapter;
use App\Services\TenantKeyManager;

/**
 * Resolves a tenant's stored (encrypted) credentials into a live connector and
 * runs health checks / actions against it. Existing Calendar and CRM adapters
 * are bridged into ConnectorContract here, so every provider — native or
 * legacy — is invocable through one uniform surface.
 */
class ConnectorManager
{
    public function __construct(
        private readonly TenantKeyManager $keys,
        private readonly ConnectorRegistry $registry,
    ) {}

    /** Decrypted credentials for a tenant's connected provider (empty if none). */
    public function credentialsFor(TenantIntegration $integration): array
    {
        if (! $integration->credentials_ref) {
            return [];
        }

        $raw = $this->keys->getSecret($integration->tenant_id, $integration->credentials_ref);

        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    public function resolve(TenantIntegration $integration): ?ConnectorContract
    {
        $creds = $this->credentialsFor($integration);
        $config = (array) ($integration->config ?? []);
        $tenantId = (string) $integration->tenant_id;

        return match ($integration->provider) {
            'slack' => new SlackConnector($tenantId, $creds, $config),
            'webhook' => new WebhookConnector($tenantId, $creds, $config),
            'http_api' => new HttpApiConnector($tenantId, $creds, $config),
            'google_calendar', 'cal_com' => new CalendarConnectorBridge($tenantId, $integration->provider, $creds),
            'hubspot', 'salesforce' => new CrmConnectorBridge($tenantId, $integration->provider, $creds),
            default => null,
        };
    }

    /**
     * Health-check a connection and persist the outcome on the integration row.
     *
     * @return array{ok: bool, error?: string, detail?: array}
     */
    public function test(TenantIntegration $integration): array
    {
        $connector = $this->resolve($integration);
        if (! $connector) {
            return ['ok' => false, 'error' => "No connector implementation for {$integration->provider}."];
        }

        try {
            $result = $connector->test();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        $integration->forceFill([
            'status' => $result['ok'] ? 'connected' : 'error',
            'last_verified_at' => now(),
            'last_error' => $result['ok'] ? null : substr((string) ($result['error'] ?? 'unknown'), 0, 500),
        ])->save();

        return $result;
    }

    /**
     * Run a catalogue-declared action. Unknown actions are refused before the
     * provider is touched, so agents can't invoke undeclared capabilities.
     *
     * @param array<string,mixed> $params
     * @return array{success: bool, data?: mixed, error?: string}
     */
    public function execute(TenantIntegration $integration, string $action, array $params = []): array
    {
        if (! in_array($action, $this->registry->actionsFor($integration->provider), true)) {
            return ['success' => false, 'error' => "Action '{$action}' is not available for {$integration->provider}."];
        }

        $connector = $this->resolve($integration);
        if (! $connector) {
            return ['success' => false, 'error' => "No connector implementation for {$integration->provider}."];
        }

        try {
            return $connector->execute($action, $params);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * Bridges the existing CalendarAdapter family onto ConnectorContract.
 */
class CalendarConnectorBridge implements ConnectorContract
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $provider,
        private readonly array $credentials,
    ) {}

    public function test(): array
    {
        try {
            $adapter = CalendarAdapter::make($this->tenantId, $this->provider, $this->credentials);
            $adapter->getAvailableSlots(now()->toDateString());

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function execute(string $action, array $params = []): array
    {
        $adapter = CalendarAdapter::make($this->tenantId, $this->provider, $this->credentials);

        return match ($action) {
            'book' => ['success' => true, 'data' => $adapter->book($params)],
            'available_slots' => ['success' => true, 'data' => $adapter->getAvailableSlots(
                (string) ($params['date'] ?? now()->toDateString()),
                $params['calendar_id'] ?? null,
            )],
            'cancel' => ['success' => $adapter->cancel((string) ($params['event_id'] ?? ''))],
            default => ['success' => false, 'error' => "Unsupported calendar action: {$action}"],
        };
    }
}

/**
 * Bridges the existing CrmAdapter family onto ConnectorContract.
 */
class CrmConnectorBridge implements ConnectorContract
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $provider,
        private readonly array $credentials,
    ) {}

    public function test(): array
    {
        try {
            CrmAdapter::make($this->tenantId, $this->provider, $this->credentials);

            return empty($this->credentials)
                ? ['ok' => false, 'error' => 'No credentials stored.']
                : ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function execute(string $action, array $params = []): array
    {
        $adapter = CrmAdapter::make($this->tenantId, $this->provider, $this->credentials);

        return match ($action) {
            'upsert_contact' => ['success' => true, 'data' => $adapter->upsertContact($params)],
            'log_activity' => ['success' => $adapter->logCallActivity($params)],
            'create_task' => ['success' => $adapter->createTask($params)],
            default => ['success' => false, 'error' => "Unsupported CRM action: {$action}"],
        };
    }
}
