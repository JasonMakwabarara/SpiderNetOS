<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZetKai;

use App\Http\Controllers\Controller;
use App\Services\ZetKai\ZetKaiException;
use App\Services\ZetKai\ZetKaiSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ZetKai <-> the Knowledge brain (plan D7 §4).
 *
 *   GET  /api/brain/zetkai/status     connected? cursor? how much was withheld?
 *   POST /api/brain/zetkai/sync-now   pull one page now (admin)
 *
 * `private_withheld` is on the status payload on purpose. A privacy filter
 * nobody can see is indistinguishable from one that has stopped working.
 */
class ZetKaiController extends Controller
{
    public function status(Request $request, ZetKaiSyncService $sync): JsonResponse
    {
        return response()->json(['data' => $sync->status((string) $request->attributes->get('tenant_id'))]);
    }

    public function syncNow(Request $request, ZetKaiSyncService $sync): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        try {
            $result = $sync->sync($tenantId);
        } catch (ZetKaiException $e) {
            $status = $e->status >= 400 && $e->status < 600 ? $e->status : 502;

            return response()->json(['message' => $e->getMessage(), 'reason' => 'zetkai_refused'], $status);
        }

        if (! $result['synced']) {
            return response()->json([
                'message' => $result['reason'] === 'disabled'
                    ? 'The ZetKai link is not switched on for this workspace.'
                    : 'This workspace is not connected to ZetKai.',
                'reason' => $result['reason'],
            ], 409);
        }

        return response()->json(['data' => $result + ['status' => $sync->status($tenantId)]]);
    }
}
