<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusinessProfileController extends Controller
{
    public function show(Request $request, AtlasDiscoveryService $discovery): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $profile = $discovery->profileForTenant($tenantId);

        return response()->json(['data' => $profile]);
    }

    public function update(Request $request, AtlasDiscoveryService $discovery): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $validated = $request->validate([
            'industry' => 'sometimes|string|max:64',
            'employee_count_band' => 'sometimes|string|max:24',
            'country' => 'sometimes|string|max:8',
            'region' => 'sometimes|string|max:64',
            'data_handles_pii' => 'sometimes|boolean',
            'issues_invoices' => 'sometimes|boolean',
            'hires_contractors' => 'sometimes|boolean',
            'biggest_time_drain' => 'sometimes|string|max:500',
        ]);

        if (! Schema::hasTable('tenant_business_profiles')) {
            return response()->json(['message' => 'Business profile not available.'], 503);
        }

        $exists = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->exists();
        $payload = array_merge($validated, ['updated_at' => now()]);

        if ($exists) {
            DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->update($payload);
        } else {
            DB::table('tenant_business_profiles')->insert(array_merge($payload, [
                'tenant_id' => $tenantId,
                'discovery_complete_pct' => 0,
                'created_at' => now(),
            ]));
        }

        $profile = $discovery->profileForTenant($tenantId);

        return response()->json(['data' => $profile]);
    }
}
