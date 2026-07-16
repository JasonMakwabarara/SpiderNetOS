<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Thrown by FeaturePackInstaller::install() when a priced pack has no
 * active pack_entitlements row for the tenant. Mapped to HTTP 402 by
 * FeaturePackController::install().
 */
class EntitlementRequiredException extends \RuntimeException
{
    public function __construct(
        public readonly string $packId,
        public readonly int $amountCents,
        public readonly string $currency,
    ) {
        parent::__construct("Pack '{$packId}' requires purchase before it can be installed.");
    }
}
