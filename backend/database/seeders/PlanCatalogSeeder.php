<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Platform plan catalog (owner-approved 2026-07-16).
 *
 * Model: flat monthly platform fee incl. a usage allowance + metered overage
 * at cost + margin. `entitlements` uses -1 for unlimited. dodo_product_id is
 * set per-environment via DODO_PRODUCT_PLAN_* env (left null until configured).
 * Idempotent — safe to re-run; edit values here or directly in the DB.
 */
class PlanCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'id' => 'launch',
                'name' => 'Launch',
                'tagline' => 'For an owner getting their first automations live.',
                'monthly_fee_cents' => 19900,   // $199/mo
                'included_usage_cents' => 5000,  // $50/mo AI usage included
                'usage_margin_pct' => 15,
                'is_custom' => false,
                'dodo_product_id' => env('DODO_PRODUCT_PLAN_LAUNCH') ?: null,
                'entitlements' => ['agents' => 3, 'flows' => 10, 'seats' => 3, 'pack_slots' => 1],
                'is_active' => true,
                'sort' => 1,
            ],
            [
                'id' => 'growth',
                'name' => 'Growth',
                'tagline' => 'For a team running production agent workflows.',
                'monthly_fee_cents' => 49900,    // $499/mo
                'included_usage_cents' => 15000,  // $150/mo AI usage included
                'usage_margin_pct' => 15,
                'is_custom' => false,
                'dodo_product_id' => env('DODO_PRODUCT_PLAN_GROWTH') ?: null,
                'entitlements' => ['agents' => 10, 'flows' => 50, 'seats' => 10, 'pack_slots' => 3],
                'is_active' => true,
                'sort' => 2,
            ],
            [
                'id' => 'enterprise',
                'name' => 'Enterprise',
                'tagline' => 'Custom annual plan for mission-critical fleets.',
                'monthly_fee_cents' => 0,        // negotiated; terms live on the subscription
                'included_usage_cents' => 0,     // pooled/custom
                'usage_margin_pct' => 15,
                'is_custom' => true,
                'dodo_product_id' => env('DODO_PRODUCT_PLAN_ENTERPRISE') ?: null,
                'entitlements' => ['agents' => -1, 'flows' => -1, 'seats' => -1, 'pack_slots' => -1],
                'is_active' => true,
                'sort' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['id' => $plan['id']], $plan);
        }
    }
}
