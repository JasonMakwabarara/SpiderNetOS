<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\TenantIntegration;
use App\Services\TenantKeyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * IntegrationsController — Phase D
 *
 * Manages tenant integration records (calendar, CRM, etc.).
 * Credentials are stored encrypted in tenant_secrets via TenantKeyManager.
 */
class IntegrationsController extends Controller
{
    public function __construct(
        private readonly TenantKeyManager $keyManager,
    ) {}

    /**
     * GET /integrations
     *
     * List configured integrations for the authenticated tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId     = $request->user()->tenant_id;
        $integrations = TenantIntegration::where('tenant_id', $tenantId)
            ->select(['id', 'provider', 'type', 'is_active', 'created_at'])
            ->get();

        return response()->json(['data' => $integrations]);
    }

    /**
     * POST /integrations/{provider}/authorize
     *
     * Store integration credentials for a provider.
     * For Google Calendar: exchange auth code for tokens.
     * For Cal.com / HubSpot: accept API key directly.
     */
    public function authorize(Request $request, string $provider): JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;

        $validated = $request->validate([
            'type'        => 'required|string|in:calendar,crm',
            'credentials' => 'required|array',
        ]);

        try {
            // Encrypt credentials via TenantKeyManager
            $encryptedRef = $this->keyManager->storeSecret(
                $tenantId,
                "integration.{$provider}",
                json_encode($validated['credentials'])
            );

            $integration = TenantIntegration::updateOrCreate(
                ['tenant_id' => $tenantId, 'provider' => $provider, 'type' => $validated['type']],
                [
                    'credentials_ref' => $encryptedRef,
                    'is_active'       => true,
                ]
            );

            Log::info('integration.authorized', [
                'tenant_id' => $tenantId,
                'provider'  => $provider,
                'type'      => $validated['type'],
            ]);

            return response()->json([
                'success'        => true,
                'integration_id' => $integration->id,
                'provider'       => $provider,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('integration.authorize_failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Authorization failed'], 500);
        }
    }
}
