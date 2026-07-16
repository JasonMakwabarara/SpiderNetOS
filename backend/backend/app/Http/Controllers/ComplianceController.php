<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasDiscoveryService;
use App\Services\ComplianceRadar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplianceController extends Controller
{
    public function obligations(Request $request, AtlasDiscoveryService $discovery, ComplianceRadar $radar): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $profile = $discovery->profileForTenant($tenantId);
        $obligations = $radar->obligationsForProfile($profile);

        return response()->json([
            'data' => $obligations,
            'profile_pct' => (int) ($profile['discovery_complete_pct'] ?? 0),
            'disclaimer' => 'Guidance only — not legal advice. Consult a qualified professional for regulatory decisions.',
        ]);
    }
}
