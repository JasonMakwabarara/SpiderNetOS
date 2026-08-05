<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\TenantIntegration;
use App\Services\Integrations\CalendarAdapter;
use App\Services\TenantKeyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CalendarController — Phase D
 *
 * POST /integrations/calendar/book
 * Internal endpoint called by voice_tools.py (via inference service)
 * to book appointments via the tenant's configured calendar integration.
 */
class CalendarController extends Controller
{
    public function __construct(
        private readonly TenantKeyManager $keyManager,
    ) {}

    /**
     * POST /integrations/calendar/book
     *
     * Book an appointment through the tenant's calendar provider.
     * Called internally from voice tools — requires tenant_id in header.
     */
    public function book(Request $request): JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;

        $validated = $request->validate([
            'date'             => 'required|string|date_format:Y-m-d',
            'time'             => 'required|string',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'attendee_name'    => 'required|string|max:200',
            'attendee_phone'   => 'nullable|string|max:20',
            'attendee_email'   => 'nullable|email|max:200',
            'description'      => 'nullable|string|max:1000',
            'calendar_id'      => 'nullable|string|max:200',
        ]);

        // Resolve integration
        $integration = TenantIntegration::where('tenant_id', $tenantId)
            ->where('type', 'calendar')
            ->where('is_active', true)
            ->first();

        if (!$integration) {
            return response()->json([
                'success' => false,
                'error'   => 'No active calendar integration configured for this tenant',
            ], 422);
        }

        try {
            $credentials = $this->resolveCredentials($integration);
            $adapter     = CalendarAdapter::make($tenantId, $integration->provider, $credentials);
            $result      = $adapter->book($validated);

            Log::info('calendar.book', [
                'tenant_id' => $tenantId,
                'provider'  => $integration->provider,
                'success'   => $result['success'],
                'event_id'  => $result['event_id'] ?? null,
            ]);

            return response()->json($result, $result['success'] ? 200 : 422);

        } catch (\Throwable $e) {
            Log::error('calendar.book_exception', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function resolveCredentials(TenantIntegration $integration): array
    {
        if (!$integration->credentials_ref) {
            return [];
        }

        try {
            $raw = $this->keyManager->getSecret($integration->tenant_id, $integration->credentials_ref);
            return json_decode($raw, true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
