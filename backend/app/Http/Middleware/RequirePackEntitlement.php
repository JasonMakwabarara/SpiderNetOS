<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PackEntitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Gates a paid feature pack's runtime routes on an active PackEntitlement.
 *
 * Applied as `pack.entitled:{pack_id}` (e.g. on the /api/sales group for the
 * sales-crm pack). Without this, install is entitlement-gated but the pack's
 * API is usable by any onboarded tenant — i.e. the monetized surface is free
 * at runtime.
 *
 * Behaviour mirrors FeaturePackInstaller::assertEntitled():
 *   - active entitlement for (tenant, pack) → allow
 *   - pack is free (no `spec.pricing` in the manifest) → allow
 *   - otherwise → 402 with the same checkout-hint shape the install endpoint returns
 *
 * NOTE (W1/W4): once plans include packs, extend the allow-path with
 * PlanEntitlementService::packIncluded($tenantId, $packId).
 */
class RequirePackEntitlement
{
    public function handle(Request $request, Closure $next, string $packId): Response
    {
        $tenantId = $request->attributes->get('tenant_id') ?? $request->user()?->tenant?->id;

        if ($tenantId && PackEntitlement::forTenant($tenantId)->where('pack_id', $packId)->active()->exists()) {
            return $next($request);
        }

        // Free packs remain open even without an entitlement row.
        $pricing = $this->packPricing($packId);
        if ($pricing === null) {
            return $next($request);
        }

        return response()->json([
            'message' => "This feature requires the {$packId} pack.",
            'checkout_hint' => true,
            'pack_id' => $packId,
            'amount_cents' => (int) round((float) ($pricing['amount'] ?? 0) * 100),
            'currency' => (string) ($pricing['currency'] ?? 'USD'),
        ], 402);
    }

    /**
     * The pack's `spec.pricing` block, or null when the pack is free/unknown.
     *
     * @return array<string, mixed>|null
     */
    private function packPricing(string $packId): ?array
    {
        $root = rtrim((string) config('feature_packs.root'), '/');
        $manifestPath = $root.'/'.$packId.'/pack.yaml';

        if (! is_readable($manifestPath)) {
            return null;
        }

        try {
            $manifest = Yaml::parseFile($manifestPath);
        } catch (\Throwable) {
            return null;
        }

        return $manifest['spec']['pricing'] ?? null;
    }
}
