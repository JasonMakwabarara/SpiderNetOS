<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\TenantIntegration;
use App\Services\Connectors\ConnectorManager;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\TenantKeyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Connector management: browse the catalogue, connect (credentials encrypted
 * into tenant_secrets), health-check, invoke actions, and disconnect.
 */
class IntegrationsController extends Controller
{
    public function __construct(
        private readonly TenantKeyManager $keyManager,
        private readonly ConnectorRegistry $registry,
        private readonly ConnectorManager $connectors,
    ) {}

    /**
     * GET /integrations/catalogue — everything connectable, merged with this
     * tenant's connection state.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $connected = TenantIntegration::forTenant($tenantId)->get()->keyBy('provider');

        $entries = array_map(function (array $entry) use ($connected) {
            $row = $connected->get($entry['provider']);
            // Never expose credential values — only whether they exist.
            $entry['connected'] = (bool) $row?->is_active;
            $entry['status'] = $row->status ?? 'not_connected';
            $entry['last_verified_at'] = $row?->last_verified_at?->toIso8601String();
            $entry['last_error'] = $row->last_error ?? null;

            return $entry;
        }, $this->registry->catalogue());

        return response()->json(['data' => $entries]);
    }

    /**
     * GET /integrations — this tenant's configured integrations.
     */
    public function index(Request $request): JsonResponse
    {
        $integrations = TenantIntegration::forTenant($request->user()->tenant_id)
            ->get(['id', 'provider', 'type', 'is_active', 'status', 'last_verified_at', 'last_error', 'created_at']);

        return response()->json(['data' => $integrations]);
    }

    /**
     * POST /integrations/{provider}/authorize — store credentials for a provider.
     * Credentials are encrypted into tenant_secrets; only the reference is kept.
     */
    public function authorize(Request $request, string $provider): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        if (! $this->registry->has($provider)) {
            return response()->json(['success' => false, 'error' => "Unknown connector: {$provider}"], 404);
        }

        $validated = $request->validate([
            'credentials' => 'required|array',
            'config' => 'sometimes|array',
        ]);

        $missing = $this->registry->missingFields($provider, $validated['credentials']);
        if ($missing) {
            return response()->json([
                'success' => false,
                'error' => 'Missing required fields: '.implode(', ', $missing),
                'missing_fields' => $missing,
            ], 422);
        }

        try {
            $ref = $this->keyManager->storeSecret(
                $tenantId,
                "integration.{$provider}",
                (string) json_encode($validated['credentials']),
            );

            $integration = TenantIntegration::updateOrCreate(
                ['tenant_id' => $tenantId, 'provider' => $provider, 'type' => $this->registry->typeFor($provider)],
                [
                    'credentials_ref' => $ref,
                    'is_active' => true,
                    'config' => $validated['config'] ?? null,
                    'status' => 'pending',
                    'last_error' => null,
                ],
            );

            // Prove the credentials work immediately rather than failing later.
            $test = $this->connectors->test($integration);

            Log::info('integration.authorized', ['tenant_id' => $tenantId, 'provider' => $provider, 'ok' => $test['ok']]);

            return response()->json([
                'success' => true,
                'integration_id' => $integration->id,
                'provider' => $provider,
                'verified' => $test['ok'],
                'error' => $test['ok'] ? null : ($test['error'] ?? null),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('integration.authorize_failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => 'Authorization failed'], 500);
        }
    }

    /** POST /integrations/{provider}/test — re-verify a stored connection. */
    public function test(Request $request, string $provider): JsonResponse
    {
        $integration = TenantIntegration::forTenant($request->user()->tenant_id)
            ->where('provider', $provider)->first();

        if (! $integration) {
            return response()->json(['ok' => false, 'error' => 'Not connected.'], 404);
        }

        return response()->json($this->connectors->test($integration));
    }

    /**
     * POST /integrations/{provider}/actions/{action} — invoke a catalogue-declared
     * capability (the surface the intelligence layer drives).
     */
    public function execute(Request $request, string $provider, string $action): JsonResponse
    {
        $integration = TenantIntegration::forTenant($request->user()->tenant_id)
            ->where('provider', $provider)->where('is_active', true)->first();

        if (! $integration) {
            return response()->json(['success' => false, 'error' => 'Not connected.'], 404);
        }

        $result = $this->connectors->execute($integration, $action, (array) $request->input('params', []));

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** DELETE /integrations/{provider} — disconnect and forget the credentials. */
    public function destroy(Request $request, string $provider): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $integration = TenantIntegration::forTenant($tenantId)->where('provider', $provider)->first();

        if (! $integration) {
            return response()->json(['success' => false, 'error' => 'Not connected.'], 404);
        }

        if ($integration->credentials_ref) {
            $this->keyManager->forgetSecret($tenantId, $integration->credentials_ref);
        }
        $integration->delete();

        return response()->json(['success' => true, 'provider' => $provider]);
    }
}
