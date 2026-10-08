<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Services\Agents\GodsEyeSnapshot;
use App\Services\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/godseye/snapshot — the live board of every agent at once
 * (plan D6 §8).
 *
 * Polled by the cockpit, so it is deliberately one call that returns the whole
 * wall rather than six that the client has to stitch together and keep in step.
 */
class GodsEyeController extends Controller
{
    public function snapshot(Request $request, GodsEyeSnapshot $snapshot): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        if (! FeatureFlag::on('cockpit.gods_eye', $tenantId)) {
            return response()->json([
                'message' => "God's Eye is not switched on for this workspace.",
                'reason' => 'gods_eye.disabled',
            ], 403);
        }

        return response()->json(['data' => $snapshot->forTenant($tenantId)]);
    }
}
