<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PackEntitlement;
use App\Services\EventStore;
use App\Services\Sales\FunnelSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $entitlement = PackEntitlement::find($entitlementId);
        if (! $entitlement) {
            Log::warning('dodo.webhook.unknown_entitlement', ['entitlement_id' => $entitlementId]);

            return response()->json(['received' => true]);
        }

        // Idempotency: a webhook-id we've already recorded on this
        // entitlement's raw_payload history is a retried delivery.
        $seen = (array) ($entitlement->raw_payload['webhook_ids'] ?? []);
        if (in_array($webhookId, $seen, true)) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }
        $seen[] = $webhookId;

        match ($type) {
            'payment.succeeded' => $this->activate($entitlement, $data, $seen, $eventStore, $funnelSetupService),
            'payment.failed' => $entitlement->update(['status' => 'revoked', 'raw_payload' => ['webhook_ids' => $seen, 'last_event' => $data]]),
            'refund.succeeded' => $entitlement->update(['status' => 'refunded', 'raw_payload' => ['webhook_ids' => $seen, 'last_event' => $data]]),
            'subscription.cancelled' => $entitlement->update(['status' => 'expired', 'raw_payload' => ['webhook_ids' => $seen, 'last_event' => $data]]),
            default => $entitlement->update(['raw_payload' => ['webhook_ids' => $seen, 'last_event' => $data]]),
        };

        return response()->json(['received' => true]);
    }

    private function activate(PackEntitlement $entitlement, array $data, array $seenWebhookIds, EventStore $eventStore, FunnelSetupService $funnelSetupService): void
    {
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
