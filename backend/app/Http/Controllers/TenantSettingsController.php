<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-level workspace settings (stored on tenants.settings JSON + name column).
 */
class TenantSettingsController extends Controller
{
    /**
     * GET /api/settings/tenant
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $s = $tenant->settings ?? [];

        return response()->json([
            'data' => [
                'org_name' => $tenant->name,
                'timezone' => $s['timezone'] ?? 'UTC',
                'auto_save_flows' => (bool) ($s['auto_save_flows'] ?? true),
                'email_notifications' => (bool) ($s['email_notifications'] ?? true),
                'budget_alerts' => (bool) ($s['budget_alerts'] ?? true),
                'anomaly_alerts' => (bool) ($s['anomaly_alerts'] ?? true),
                'webhook_url' => (string) ($s['webhook_url'] ?? ''),
                'automation_level' => $tenant->automation_level ?? 'assisted',
            ],
        ]);
    }

    /**
     * PUT /api/settings/tenant
     */
    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'org_name' => 'sometimes|string|max:255',
            'timezone' => 'sometimes|string|max:64',
            'auto_save_flows' => 'sometimes|boolean',
            'email_notifications' => 'sometimes|boolean',
            'budget_alerts' => 'sometimes|boolean',
            'anomaly_alerts' => 'sometimes|boolean',
            'webhook_url' => 'sometimes|nullable|string|max:2048',
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        if (array_key_exists('org_name', $payload)) {
            $tenant->name = $payload['org_name'];
        }

        $settings = $tenant->settings ?? [];
        $keys = ['timezone', 'auto_save_flows', 'email_notifications', 'budget_alerts', 'anomaly_alerts', 'webhook_url'];
        foreach ($keys as $k) {
            if (array_key_exists($k, $payload)) {
                $settings[$k] = $payload[$k];
            }
        }
        $tenant->settings = $settings;
        $tenant->save();

        return $this->show($request);
    }
}
