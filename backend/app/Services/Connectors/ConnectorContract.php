<?php

declare(strict_types=1);

namespace App\Services\Connectors;

/**
 * A third-party connector a tenant can connect and agents can invoke.
 *
 * Connectors are resolved per tenant with decrypted credentials by
 * ConnectorManager, then either health-checked (`test`) or driven generically
 * (`execute`) — which is what lets the intelligence layer call any connector
 * action without knowing the provider.
 */
interface ConnectorContract
{
    /** Verify the stored credentials actually work. @return array{ok: bool, error?: string, detail?: array} */
    public function test(): array;

    /**
     * Invoke a named capability.
     *
     * @param array<string,mixed> $params
     * @return array{success: bool, data?: mixed, error?: string}
     */
    public function execute(string $action, array $params = []): array;
}
