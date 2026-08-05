<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\EventStore;
use App\Services\Sales\FunnelSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dodo Payments webhook — signature verified by VerifyDodoSignature
 * middleware. Idempotent on webhook-id (Standard Webhooks spec) so retried
 * deliveries never double-apply. Routes two flows by metadata:
 *   - entitlement_id           → one-time feature-pack purchases
 *   - tenant_subscription_id   → recurring platform-plan subscriptions
 */
class DodoWebhookController extends Controller
{
    public function handle(Request $request, EventStore $eventStore, FunnelSetupService $funnelSetupService): JsonResponse
    {
        $payload = $request->json()->all();
        $webhookId = (string) $request->header('webhook-id', '');
        $type = (string) ($payload['type'] ?? '');
        $data = (array) ($payload['data'] ?? $payload['object'] ?? []);
        $metadata = (array) ($data['metadata'] ?? []);

        // Platform-plan subscription events carry tenant_subscription_id (or are
        // subscription.* without an entitlement) → subscription flow.
        if (! empty($metadata['tenant_subscription_id'])
            || (str_starts_with($type, 'subscription.') && empty($metadata['entitlement_id']))) {
            return response()->json($this->handleSubscription($type, $data, $metadata, $webhookId, $eventStore));
        }

        $entitlementId = $metadata['entitlement_id'] ?? null;
        if (! $entitlementId) {
            Log::info('dodo.webhook.unrouted', ['type' => $type, 'webhook_id' => $webhookId]);

            return response()->json(['received' => true]);
        }

        // Serialise concurrent retries on this entitlement so the
        // read-check-write of the webhook-id dedup below is atomic; without
        // the row lock two duplicate deliveries can both pass the `in_array`
        // gate and double-apply the state transition.
        $result = DB::transaction(function () use ($entitlementId, $webhookId, $type, $data, $eventStore, $funnelSetupService) {
            /** @var PackEntitlement|null $entitlement */
            $entitlement = PackEntitlement::whereKey($entitlementId)->lockForUpdate()->first();
            if (! $entitlement) {
                Log::warning('dodo.webhook.unknown_entitlement', ['entitlement_id' => $entitlementId]);

                return ['received' => true];
            }

            // Idempotency: a webhook-id we've already recorded on this
            // entitlement's raw_payload history is a retried delivery.
            $seen = (array) ($entitlement->raw_payload['webhook_ids'] ?? []);
            if (in_array($webhookId, $seen, true)) {
                return ['received' => true, 'duplicate' => true];
            }
            $seen[] = $webhookId;

            match ($type) {
                'payment.succeeded' => $this->activate($entitlement, $data, $seen, $eventStore, $funnelSetupService),
                'payment.failed' => $entitlement->update(['status' => 'revoked', 'raw_payload' => ['webhook_ids' => $seen, 'last_event' => $data]]),
                'refund.succeeded' => $entitlement->update(['status' => 'refunded', 'raw_payload' => ['webhook_ids' => $seen, 'last_event' => $data]]),
                'subscription.cancelled' => $entitlement->update(['status' => 'expired', 'raw_payload' => ['webhook_ids' => $seen, 'last_event' => $data]]),
                default => $entitlement->update(['raw_payload' => ['webhook_ids' => $seen, 'last_event' => $data]]),
            };

            return ['received' => true];
        });

        return response()->json($result);
    }

    private function activate(PackEntitlement $entitlement, array $data, array $seenWebhookIds, EventStore $eventStore, FunnelSetupService $funnelSetupService): void
    {
        // Guard the partial-unique index (one active row per tenant+pack): if
        // this entitlement is already active, or a different active one exists
        // for the same tenant+pack (e.g. a duplicate re-purchase), record the
        // webhook and no-op rather than creating a second active row — which
        // would throw on the unique index and make Dodo retry the webhook
        // forever. A duplicate purchase is flagged for manual reconciliation.
        if ($entitlement->status !== 'active') {
            $conflict = PackEntitlement::query()
                ->where('tenant_id', $entitlement->tenant_id)
                ->where('pack_id', $entitlement->pack_id)
                ->where('status', 'active')
                ->whereKeyNot($entitlement->getKey())
                ->exists();

            if ($conflict) {
                Log::warning('dodo.webhook.duplicate_active_entitlement', [
                    'tenant_id' => $entitlement->tenant_id,
                    'pack_id' => $entitlement->pack_id,
                    'entitlement_id' => $entitlement->id,
                ]);
                $entitlement->update(['raw_payload' => ['webhook_ids' => $seenWebhookIds, 'last_event' => $data]]);

                return;
            }
        }

        $entitlement->update([
            'status' => 'active',
            'provider_payment_id' => $data['payment_id'] ?? $data['id'] ?? null,
            'provider_customer_id' => $data['customer']['customer_id'] ?? $data['customer_id'] ?? null,
            'purchased_at' => now(),
            'raw_payload' => ['webhook_ids' => $seenWebhookIds, 'last_event' => $data],
        ]);

        $eventStore->append(
            $entitlement->tenant_id, 'pack_entitlement', $entitlement->id,
            'pack.purchase.completed',
            ['pack_id' => $entitlement->pack_id, 'entitlement_id' => $entitlement->id],
        );

        if ($entitlement->pack_id === 'sales-crm') {
            // Kicks off funnel_setup.purchased -> the discovery interview is
            // ready the moment the tenant opens /sales/funnel-setup.
            $funnelSetupService->getOrCreate($entitlement->tenant_id);
        }
    }

    /**
     * Recurring platform-plan subscription lifecycle. Reconciles by the local
     * tenant_subscription_id (set at checkout) or the Dodo subscription id.
     * Idempotent via meta.webhook_ids under a row lock; each row is the single
     * live subscription for its tenant (subscribe() reuses one row).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function handleSubscription(string $type, array $data, array $metadata, string $webhookId, EventStore $eventStore): array
    {
        $localId = $metadata['tenant_subscription_id'] ?? null;
        $dodoSubId = $data['subscription_id'] ?? $data['id'] ?? null;

        return DB::transaction(function () use ($type, $data, $metadata, $webhookId, $localId, $dodoSubId, $eventStore) {
            $query = TenantSubscription::query()->lockForUpdate();
            $sub = $localId
                ? $query->find($localId)
                : ($dodoSubId ? $query->where('dodo_subscription_id', $dodoSubId)->first() : null);

            // Self-provisioning: a subscription that did not originate from our
            // own subscribe() flow (e.g. a Dodo-hosted checkout) carries only
            // tenant_id (+ plan_id/product_id) metadata. Create the local row
            // so fulfillment still lands. Restores the trunk fulfiller's
            // capability on our tenant_subscriptions architecture.
            if (! $sub && ! empty($metadata['tenant_id']) && Tenant::whereKey($metadata['tenant_id'])->exists()) {
                $planId = $this->resolvePlanId($metadata, $data);
                if ($planId) {
                    $sub = TenantSubscription::create([
                        'tenant_id' => $metadata['tenant_id'],
                        'plan_id' => $planId,
                        'status' => 'pending',
                        'dodo_subscription_id' => $dodoSubId,
                    ]);
                }
            }

            if (! $sub) {
                Log::warning('dodo.webhook.unknown_subscription', [
                    'local_id' => $localId, 'dodo_sub_id' => $dodoSubId, 'type' => $type,
                ]);

                return ['received' => true];
            }

            $seen = (array) ($sub->meta['webhook_ids'] ?? []);
            if (in_array($webhookId, $seen, true)) {
                return ['received' => true, 'duplicate' => true];
            }
            $seen[] = $webhookId;

            $status = match ($type) {
                'subscription.active', 'subscription.renewed' => 'active',
                'subscription.on_hold', 'subscription.failed' => 'past_due',
                'subscription.cancelled', 'subscription.expired' => 'cancelled',
                default => $sub->status,
            };

            $updates = [
                'status' => $status,
                'meta' => array_merge($sub->meta ?? [], ['webhook_ids' => $seen, 'last_event' => $data]),
            ];
            if ($dodoSubId && ! $sub->dodo_subscription_id) {
                $updates['dodo_subscription_id'] = $dodoSubId;
            }
            if (! empty($data['customer']['customer_id'])) {
                $updates['dodo_customer_id'] = $data['customer']['customer_id'];
            }
            if (! empty($data['previous_billing_date'])) {
                $updates['current_period_start'] = $data['previous_billing_date'];
            }
            if (! empty($data['next_billing_date'])) {
                $updates['current_period_end'] = $data['next_billing_date'];
            }
            if ($status === 'cancelled') {
                $updates['cancelled_at'] = now();
            }

            $sub->update($updates);

            // Mirror the plan onto the tenant so legacy tenants.plan reads and
            // the billing summary reflect the live plan; limits mirrored for
            // legacy consumers (catalog entitlements preferred, config/dodo.php
            // plan limits as fallback).
            if ($status === 'active') {
                $tenantUpdates = [
                    'plan' => $sub->plan_id,
                    'status' => 'active',
                    'subscribed_at' => now(),
                ];
                if ($limits = $this->limitsForPlan($sub->plan_id)) {
                    $tenantUpdates['limits'] = json_encode($limits);
                }
                Tenant::whereKey($sub->tenant_id)->update($tenantUpdates);
            }

            $eventStore->append(
                $sub->tenant_id, 'tenant_subscription', $sub->id,
                'platform.'.$type,
                ['plan_id' => $sub->plan_id, 'status' => $status],
            );

            return ['received' => true];
        });
    }

    /**
     * Resolve the plan id for a self-provisioned subscription: explicit
     * metadata.plan_id (validated against the catalog or config/dodo.php), or
     * a reverse product-id lookup across both sources.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $data
     */
    private function resolvePlanId(array $metadata, array $data): ?string
    {
        $candidate = $metadata['plan_id'] ?? null;
        if ($candidate && (\App\Models\Plan::whereKey($candidate)->exists() || config("dodo.plans.{$candidate}"))) {
            return (string) $candidate;
        }

        $productId = $data['product_id'] ?? null;
        if ($productId) {
            $byCatalog = \App\Models\Plan::where('dodo_product_id', $productId)->value('id');
            if ($byCatalog) {
                return (string) $byCatalog;
            }
            foreach ((array) config('dodo.plans', []) as $planId => $plan) {
                if (($plan['product_id'] ?? null) === $productId) {
                    return (string) $planId;
                }
            }
        }

        return null;
    }

    /**
     * Limits to mirror onto tenants.limits on activation: catalog entitlements
     * first, then the trunk-era config/dodo.php plan limits.
     *
     * @return array<string, int>|null
     */
    private function limitsForPlan(string $planId): ?array
    {
        $plan = \App\Models\Plan::find($planId);
        if ($plan && is_array($plan->entitlements) && $plan->entitlements) {
            return $plan->entitlements;
        }

        $cfg = config("dodo.plans.{$planId}.limits");

        return is_array($cfg) ? $cfg : null;
    }
}
