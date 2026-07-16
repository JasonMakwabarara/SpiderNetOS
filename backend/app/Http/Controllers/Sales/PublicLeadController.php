<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Sales\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unauthenticated lead-capture endpoint, embedded on the tenant's own
 * external site. See routes/api.php for the throttle:lead_capture limiter.
 */
class PublicLeadController extends Controller
{
    public function __construct(
        private readonly LeadService $leadService,
    ) {}

    public function store(Request $request, string $tenant): JsonResponse
    {
        $tenantModel = Tenant::where('id', $tenant)->where('status', 'active')->first();

        if (! $tenantModel) {
            // Deliberately generic — do not confirm/deny whether a tenant id exists.
            return response()->json(['message' => 'Not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:32',
            'whatsapp_number' => 'nullable|string|max:32',
            'email_opt_in' => 'sometimes|boolean',
            'whatsapp_opt_in' => 'sometimes|boolean',
        ]);

        if (empty($validated['email']) && empty($validated['phone']) && empty($validated['whatsapp_number'])) {
            return response()->json(['message' => 'At least one of email, phone, or whatsapp_number is required.'], 422);
        }

        $validated['source'] = 'landing_page';

        $lead = $this->leadService->create($tenantModel->id, $validated);

        return response()->json(['data' => ['id' => $lead->id, 'stage' => $lead->stage]], 201);
    }
}
