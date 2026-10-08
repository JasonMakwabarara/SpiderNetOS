<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hannah;

use App\Http\Controllers\Controller;
use App\Services\Hannah\HannahApiException;
use App\Services\Hannah\HannahHandoffService;
use App\Services\Hannah\HannahNotConfiguredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Hannah AI hand-off (plan D7 §1).
 *
 *   GET  /api/hannah/link         is this workspace linked, and is the brain ready
 *   POST /api/hannah/link         provision the tenant's Hannah workspace (admin)
 *   POST /api/hannah/brand/sync   push brand/voice.md + offer + ICP across (admin)
 *   GET  /api/hannah/deep-link    a single-use link that lands the owner inside Hannah
 *
 * `hannah_ai` is the external product. The `hannah` character inside
 * SpiderNetOS is a different thing and shares no namespace with it — not the
 * connector id, not the tool prefix, not the flags (ADR-0002).
 */
class HannahHandoffController extends Controller
{
    public function show(Request $request, HannahHandoffService $handoff): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        return response()->json(['data' => $handoff->status($tenantId)]);
    }

    public function link(Request $request, HannahHandoffService $handoff): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $request->validate([
            'accept_terms' => ['required', 'accepted'],
        ], [
            'accept_terms.accepted' => "Hannah AI's terms have to be accepted before an account is created in your name.",
        ]);

        try {
            $link = $handoff->link($tenantId, $request->user(), true);
        } catch (HannahNotConfiguredException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'hannah_not_configured'], 409);
        } catch (HannahApiException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'hannah_refused'], 502);
        }

        return response()->json(['data' => $handoff->status($tenantId) + ['link_id' => $link->id]], 201);
    }

    public function syncBrand(Request $request, HannahHandoffService $handoff): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        try {
            $result = $handoff->syncBrand($tenantId, $request->boolean('force'));
        } catch (HannahNotConfiguredException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'hannah_not_configured'], 409);
        } catch (HannahApiException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'hannah_refused'], 502);
        }

        if (! $result['synced'] && $result['reason'] === 'not_linked') {
            return response()->json(['message' => 'This workspace is not linked to Hannah AI yet.', 'reason' => 'not_linked'], 409);
        }

        return response()->json(['data' => $result]);
    }

    public function deepLink(Request $request, HannahHandoffService $handoff): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        try {
            $link = $handoff->deepLink($tenantId, (string) $request->input('to', '/'));
        } catch (HannahNotConfiguredException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'not_linked'], 409);
        } catch (HannahApiException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'hannah_refused'], 502);
        }

        return response()->json(['data' => $link]);
    }
}
