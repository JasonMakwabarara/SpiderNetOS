<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Sales\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function __construct(
        private readonly LeadService $leadService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $query = Lead::forTenant($tenant->id);

        if ($request->filled('stage')) {
            $query->where('stage', $request->query('stage'));
        }

        $leads = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 25));

        return response()->json(['data' => $leads]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $lead = Lead::forTenant($tenant->id)->with('deals')->findOrFail($id);

        return response()->json(['data' => $lead]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:32',
            'whatsapp_number' => 'nullable|string|max:32',
            'source' => 'sometimes|string|max:64',
            'email_opt_in' => 'sometimes|boolean',
            'whatsapp_opt_in' => 'sometimes|boolean',
            'custom' => 'sometimes|array',
        ]);

        if (empty($validated['email']) && empty($validated['phone']) && empty($validated['whatsapp_number'])) {
            return response()->json(['message' => 'At least one of email, phone, or whatsapp_number is required.'], 422);
        }

        $lead = $this->leadService->create($tenant->id, $validated);

        return response()->json(['data' => $lead], 201);
    }

    public function updateStage(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $lead = Lead::forTenant($tenant->id)->findOrFail($id);

        $validated = $request->validate([
            'stage' => 'required|string|in:'.implode(',', Lead::STAGES),
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $lead = $this->leadService->transitionStage($lead, $validated['stage'], array_filter([
                'reason' => $validated['reason'] ?? null,
            ]));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $lead]);
    }

    public function pipelineSummary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $byStage = Lead::forTenant($tenant->id)
            ->selectRaw('stage, count(*) as count')
            ->groupBy('stage')
            ->pluck('count', 'stage');

        $staleLeads = Lead::forTenant($tenant->id)
            ->whereIn('stage', ['captured', 'qualified', 'engaged'])
            ->where(function ($q) {
                $q->whereNull('last_contacted_at')->orWhere('last_contacted_at', '<', now()->subDays(3));
            })
            ->count();

        $openDeals = Deal::forTenant($tenant->id)->whereNotIn('stage', ['won', 'lost'])->count();

        return response()->json(['data' => [
            'by_stage' => $byStage,
            'stale_leads' => $staleLeads,
            'open_deals' => $openDeals,
        ]]);
    }
}
