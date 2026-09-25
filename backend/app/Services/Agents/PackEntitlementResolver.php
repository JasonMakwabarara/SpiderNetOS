<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\PackEntitlement;
use App\Services\PlanEntitlementService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Is a tenant entitled to the feature pack a skill card belongs to?
 * Mirrors FeaturePackInstaller::assertEntitled() and the
 * RequirePackEntitlement middleware (both private / request-bound, so the
 * runtime carries its own copy of the rule):
 *
 *   platform skill (no pack) → yes; active PackEntitlement → yes;
 *   free pack (no spec.pricing) → yes; included in the plan → yes; else no
 *   and `checkoutHint()` gives the 402 body the cockpit already understands.
 */
final class PackEntitlementResolver
{
    public function entitled(string $tenantId, ?string $packId): bool
    {
        if ($packId === null || $packId === '') {
            return true;
        }

        if (PackEntitlement::forTenant($tenantId)->where('pack_id', $packId)->active()->exists()) {
            return true;
        }

        if ($this->pricing($packId) === null) {
            return true; // free or unknown pack
        }

        if (class_exists(PlanEntitlementService::class)) {
            try {
                if (app(PlanEntitlementService::class)->packIncluded($tenantId, $packId)) {
                    return true;
                }
            } catch (\Throwable $e) {
                Log::debug('plan entitlement lookup failed', ['pack_id' => $packId, 'error' => $e->getMessage()]);
            }
        }

        return false;
    }

    /** @return array{checkout_hint: bool, pack_id: string, amount_cents: int, currency: string} */
    public function checkoutHint(string $packId): array
    {
        $pricing = $this->pricing($packId) ?? [];

        return [
            'checkout_hint' => true,
            'pack_id' => $packId,
            'amount_cents' => (int) round((float) ($pricing['amount'] ?? 0) * 100),
            'currency' => (string) ($pricing['currency'] ?? 'USD'),
        ];
    }

    /** @return array<string, mixed>|null the pack's spec.pricing block */
    private function pricing(string $packId): ?array
    {
        $root = rtrim((string) env('FEATURE_PACKS_ROOT', dirname(base_path()).'/packages/feature-packs'), '/');
        $manifestPath = $root.'/'.$packId.'/pack.yaml';

        if (! is_readable($manifestPath)) {
            return null;
        }

        try {
            $manifest = Yaml::parseFile($manifestPath);
        } catch (\Throwable) {
            return null;
        }

        $pricing = $manifest['spec']['pricing'] ?? null;

        return is_array($pricing) ? $pricing : null;
    }
}
