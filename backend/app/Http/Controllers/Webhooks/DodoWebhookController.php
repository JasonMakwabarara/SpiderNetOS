<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PackEntitlement;
use App\Services\EventStore;
use App\Services\Sales\FunnelSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dodo Payments webhook — signature verified by VerifyDodoSignature
 * middleware. Idempotent on webhook-id (Standard Webhooks spec) so retried
 * deliveries never double-activate an entitlement.
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

        $entitlementId = $metadata['entitlement_id'] ?? null;
        if (! $entitlementId) {
            Log::info('dodo.webhook.no_entitlement_metadata', ['type' => $type, 'webhook_id' => $webhookId]);

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
}
