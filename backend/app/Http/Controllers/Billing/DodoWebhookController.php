<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Payments\DodoPaymentsService;
use App\Services\Payments\DodoSubscriptionFulfiller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/webhooks/dodo
 *
 * Signature verification is the gate (Standard Webhooks HMAC); the route
 * is public like the Twilio voice webhooks. Deliveries are recorded by
 * webhook-id for idempotency — Dodo retries aggressively.
 */
class DodoWebhookController extends Controller
{
    public function __construct(
        private readonly DodoPaymentsService $dodo,
        private readonly DodoSubscriptionFulfiller $fulfiller,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $webhookId = (string) $request->header('webhook-id', '');

        $verified = $this->dodo->verifyWebhookSignature(
            $body,
            $webhookId,
            (string) $request->header('webhook-timestamp', ''),
            (string) $request->header('webhook-signature', ''),
        );

        if (! $verified) {
            Log::warning('dodo-> webhook: signature verification failed', [
                'webhook_id' => $webhookId,
            ]);

            return response()->json(['status' => 'invalid_signature'], 401);
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        // Idempotency: first delivery wins, replays are acknowledged as no-ops.
        $firstDelivery = DB::table('billing_webhook_events')->insertOrIgnore([
            'webhook_id' => $webhookId,
            'provider' => DodoPaymentsService::GATEWAY_CODE,
            'event_type' => (string) ($payload['type'] ?? 'unknown'),
            'payload' => $body,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($firstDelivery === 0) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $this->fulfiller->handle($payload);

        return response()->json(['received' => true]);
    }
}
