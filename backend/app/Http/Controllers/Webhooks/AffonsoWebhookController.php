<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\HandleAffonsoWebhookJob;
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

        // insertOrIgnore, not insert-and-catch. Postgres aborts the entire
        // transaction on a failed INSERT, so catching the unique violation and
        // carrying on left the connection unusable — every query after it died
        // with "current transaction is aborted". ON CONFLICT DO NOTHING never
        // raises, and a return of 0 rows is the duplicate.
        $inserted = DB::table('webhook_receipts')->insertOrIgnore([
            'id' => $receiptId,
            'tenant_id' => $tenant,
            'provider' => 'affonso',
            'event_id' => mb_substr($eventId, 0, 128),
            'event_type' => mb_substr($type, 0, 64),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        HandleAffonsoWebhookJob::dispatch($tenant, $receiptId);

        return response()->json(['received' => true]);
    }
}
