<?php

declare(strict_types=1);

namespace App\Services\Spend\Rails;

use App\Models\PaymentInstruction;

/**
 * Dodo Payments disbursement rail — STUB (record-only V1).
 *
 * Unlike the platform-level \App\Services\Integrations\DodoPaymentsAdapter
 * (packs sold BY SpiderNetOS with credentials from config/services.php),
 * this rail is per-tenant: credentials are resolved from the tenant's
 * tenant_integrations row (provider 'dodo_payments') whose credentials_ref
 * points at an encrypted tenant_secrets entry — the same pattern
 * StripeAdapter uses.
 *
 * Intended wire conventions once payouts are enabled (mirroring the
 * platform adapter, confirmed against docs.dodopayments.com):
 *   base URL   https://test.dodopayments.com | https://live.dodopayments.com
 *              (selected by credentials['environment'], default 'test')
 *   auth       Authorization: Bearer <credentials['api_key']>
 *   payouts    POST {base}/payouts -> {payout_id, status, ...}
 *   status     GET  {base}/payouts/{payout_id}
 *   webhooks   Standard Webhooks spec (webhook-id / webhook-signature /
 *              webhook-timestamp), 300s replay tolerance.
 *
 * Until Dodo payouts are enabled for tenants, every mutating call throws and
 * PaymentRailManager callers should treat the tenant as record-only.
 */
class DodoPaymentsRailAdapter implements PaymentRailInterface
{
    private const SUPPORTED_CURRENCIES = ['USD', 'EUR', 'GBP'];

    /**
     * @param  array<string, mixed>  $credentials  Per-tenant credentials
     *                                             resolved from tenant_secrets (api_key, webhook_secret,
     *                                             environment). May be empty while the integration is connected
     *                                             but payouts are not yet provisioned.
     */
    public function __construct(
        private readonly array $credentials = [],
    ) {}

    public function name(): string
    {
        return 'dodo_payments';
    }

    public function createDisbursement(PaymentInstruction $instruction): array
    {
        throw new \RuntimeException('Dodo disbursements not yet enabled — record-only mode');
    }

    public function getDisbursementStatus(string $externalReference): array
    {
        throw new \RuntimeException('Dodo disbursements not yet enabled — record-only mode');
    }

    public function cancelDisbursement(string $externalReference): array
    {
        throw new \RuntimeException('Dodo disbursements not yet enabled — record-only mode');
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true);
    }
}
