<?php

declare(strict_types=1);

namespace App\Services\Spend\Rails;

use App\Services\TenantKeyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Chooses the payment rail for a tenant: Dodo Payments when the tenant has
 * an active dodo_payments integration configured, record-only otherwise.
 *
 * Credentials follow the StripeAdapter convention — tenant_integrations
 * holds a credentials_ref key into the encrypted tenant_secrets store.
 * Missing/undecryptable credentials are tolerated (the Dodo rail is a stub
 * and asserts on use, not on construction).
 */
class PaymentRailManager
{
    public function __construct(
        private readonly TenantKeyManager $keyManager,
    ) {}

    public function railFor(string $tenantId): PaymentRailInterface
    {
        $integration = DB::table('tenant_integrations')
            ->where('tenant_id', $tenantId)
            ->where('provider', 'dodo_payments')
            ->where('is_active', true)
            ->first();

        if ($integration === null) {
            return new RecordOnlyRail;
        }

        return new DodoPaymentsRailAdapter(
            $this->resolveCredentials($tenantId, $integration->credentials_ref ?? null)
        );
    }

    /** @return array<string, mixed> */
    private function resolveCredentials(string $tenantId, ?string $credentialsRef): array
    {
        if (! $credentialsRef) {
            return [];
        }

        try {
            $secret = $this->keyManager->getSecret($tenantId, $credentialsRef);
        } catch (\Throwable $e) {
            Log::warning('PaymentRailManager: credential resolution failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($secret === null) {
            return [];
        }

        $decoded = json_decode($secret, true);

        return is_array($decoded) ? $decoded : ['api_key' => $secret];
    }
}
