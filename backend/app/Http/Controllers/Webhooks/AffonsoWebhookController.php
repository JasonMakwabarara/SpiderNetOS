<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\HandleAffonsoWebhookJob;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Affonso webhook receiver (signature verified by VerifyAffonsoSignature).
 * Records the event once (unique provider + event id), acknowledges within
 * milliseconds (Affonso allows 30 s) and processes it on the queue.
 */
class AffonsoWebhookController extends Controller
{
    public function handle(Request $request, string $tenant): JsonResponse
    {
        $payload = (array) $request->json()->all();
        $eventId = trim((string) ($payload['id'] ?? ''));
        $type = trim((string) ($payload['type'] ?? ''));

        if ($eventId === '' || $type === '') {
            return response()->json(['message' => 'Malformed event: id and type are required.'], 422);
        }

        $receiptId = (string) Str::uuid();

        try {
            DB::table('webhook_receipts')->insert([
                'id' => $receiptId,
                'tenant_id' => $tenant,
                'provider' => 'affonso',
                'event_id' => mb_substr($eventId, 0, 128),
                'event_type' => mb_substr($type, 0, 64),
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true) || str_contains(strtolower($e->getMessage()), 'unique')) {
                return response()->json(['received' => true, 'duplicate' => true]);
            }
            throw $e;
        }

        HandleAffonsoWebhookJob::dispatch($tenant, $receiptId);

        return response()->json(['received' => true]);
    }
}
