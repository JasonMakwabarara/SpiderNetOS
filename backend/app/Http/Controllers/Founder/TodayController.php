<?php

declare(strict_types=1);

namespace App\Http\Controllers\Founder;

use App\Http\Controllers\Controller;
use App\Services\Founder\FounderBriefService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/today — Needs-You Today (plan D8 #3): the deterministic brief
 * with at most seven ranked items, what ran overnight, and Atlas's one more
 * question. Replaces the hardcoded dashboard suggestions.
 */
class TodayController extends Controller
{
    public function index(Request $request, FounderBriefService $briefs): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $for = null;
        if (is_string($request->input('date')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->input('date'))) {
            $for = new \DateTimeImmutable($request->input('date'));
        }

        $brief = $briefs->compose($tenantId, $for);

        if (! $request->boolean('markdown')) {
            unset($brief['markdown']);
        }

        return response()->json(['data' => $brief]);
    }
}
