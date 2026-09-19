<?php

namespace App\Http\Controllers\Operating;

use App\Http\Controllers\Controller;
use App\Models\AwarenessItem;
use App\Models\BusinessAsset;
use App\Models\FeaturePack;
use App\Models\Lead;
use App\Models\TenantAlignmentProfile;
use App\Models\WeeklyRhythm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Priestley Five A's operating rhythm, productized:
 *   Alignment      -> alignment()
 *   Awareness      -> awareness()/raiseAwareness()/resolveAwareness()
 *   Accountability -> scoreboard() (reads installed pack `targets:`)
 *   Activity       -> current handled by Jobs\WeeklyRhythmJob + weeklyRhythm()
 *   Assets         -> assets() (reads business_assets, registered elsewhere
 *                     — e.g. FunnelSetupService::activate())
 */
class OperatingController extends Controller
{
    public function showAlignment(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $profile = TenantAlignmentProfile::find($tenant->id);

        return response()->json(['data' => $profile ?? ['tenant_id' => $tenant->id]]);
    }

    public function updateAlignment(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'origin_story' => 'sometimes|nullable|string|max:2000',
            'mission' => 'sometimes|nullable|string|max:2000',
            'vision' => 'sometimes|nullable|string|max:2000',
            'values' => 'sometimes|array',
            'three_year_targets' => 'sometimes|array',
            'one_year_targets' => 'sometimes|array',
            'ninety_day_targets' => 'sometimes|array',
        ]);

        $profile = TenantAlignmentProfile::updateOrCreate(
            ['tenant_id' => $tenant->id],
            array_merge($validated, [
                'current_cycle_started_at' => TenantAlignmentProfile::find($tenant->id)?->current_cycle_started_at ?? now(),
            ]),
        );

        return response()->json(['data' => $profile]);
    }

    public function orgChart(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $owner = DB::table('users')->where('tenant_id', $tenant->id)->orderBy('created_at')->first(['id', 'name', 'email']);

        $roles = [];
        foreach (FeaturePack::where('tenant_id', $tenant->id)->where('status', 'installed')->get() as $pack) {
            $manifest = $pack->manifest ?? [];
            $roleMap = $manifest['spec']['roles'] ?? [];
            foreach ($manifest['spec']['provides']['dynamic_agents'] ?? [] as $agentDef) {
                $agentId = $agentDef['id'] ?? null;
                if (! $agentId) {
                    continue;
                }
                $slug = Str::slug($pack->pack_id, '_').'_'.Str::slug($agentId, '_');
                $agentRow = DB::table('agents')->where('tenant_id', $tenant->id)->where('slug', $slug)->first(['status', 'activated_at']);

                $roles[] = [
                    'role' => $roleMap[$agentId] ?? $agentId,
                    'agent_slug' => $slug,
                    'agent_name' => $agentDef['displayName'] ?? $agentId,
                    'pack_id' => $pack->pack_id,
                    'status' => $agentRow->status ?? 'not_provisioned',
                ];
            }
        }

        return response()->json(['data' => [
            'key_person_of_influence' => $owner,
            'central_brain' => 'atlas',
            'roles' => $roles,
        ]]);
    }

    public function scoreboard(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $metrics = [];
        foreach (FeaturePack::where('tenant_id', $tenant->id)->where('status', 'installed')->get() as $pack) {
            foreach ($pack->manifest['spec']['provides']['targets'] ?? [] as $target) {
                $metrics[] = array_merge($target, [
                    'pack_id' => $pack->pack_id,
                    'actual' => $this->computeActual($tenant->id, $target['metric'] ?? ''),
                ]);
            }
        }

        return response()->json(['data' => $metrics]);
    }

    public function awareness(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $items = AwarenessItem::forTenant($tenant->id)->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $items]);
    }

    public function raiseAwareness(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'detail' => 'sometimes|nullable|string|max:2000',
            'severity' => 'sometimes|string|in:info,warning,critical',
        ]);

        $item = AwarenessItem::create(array_merge($validated, [
            'tenant_id' => $tenant->id,
            'source' => 'human',
            'status' => 'open',
            'raised_by' => (string) $request->user()->id,
        ]));

        return response()->json(['data' => $item], 201);
    }

    public function resolveAwareness(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $item = AwarenessItem::forTenant($tenant->id)->findOrFail($id);
        $item->update(['status' => 'resolved', 'resolved_at' => now()]);

        return response()->json(['data' => $item]);
    }

    public function weeklyRhythm(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $rhythms = WeeklyRhythm::forTenant($tenant->id)->orderByDesc('week_start')->limit(12)->get();

        return response()->json(['data' => $rhythms]);
    }

    public function assets(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $assets = BusinessAsset::forTenant($tenant->id)->orderByDesc('created_at')->get();

        $byQuarter = $assets->groupBy('quarter');

        return response()->json(['data' => $byQuarter]);
    }

    private function computeActual(string $tenantId, string $metric): ?float
    {
        return match ($metric) {
            'lead_conversion_rate' => $this->leadConversionRate($tenantId),
            'response_time_p50' => $this->avgResponseMinutes($tenantId),
            default => null, // e.g. cac_ltv_ratio needs spend data — not yet tracked, surfaced as null (manual entry)
        };
    }

    private function leadConversionRate(string $tenantId): ?float
    {
        $total = Lead::forTenant($tenantId)->count();
        if ($total === 0) {
            return null;
        }
        $won = Lead::forTenant($tenantId)->where('stage', 'won')->count();

        return round($won / $total, 4);
    }

    private function avgResponseMinutes(string $tenantId): ?float
    {
        $rows = DB::table('leads')
            ->join('conversations', 'conversations.lead_id', '=', 'leads.id')
            ->join('conversation_messages', function ($join) {
                $join->on('conversation_messages.conversation_id', '=', 'conversations.id')
                    ->where('conversation_messages.direction', '=', 'out');
            })
            ->where('leads.tenant_id', $tenantId)
            ->select('leads.id as lead_id', 'leads.created_at as lead_created_at', 'conversation_messages.created_at as first_response_at')
            ->orderBy('conversation_messages.created_at')
            ->get()
            ->groupBy('lead_id') // first (earliest) outbound message per lead, after ORDER BY
            ->map(fn ($group) => $group->first());

        if ($rows->isEmpty()) {
            return null;
        }

        $minutes = $rows->map(function ($row) {
            return Carbon::parse($row->lead_created_at)
                ->diffInMinutes(Carbon::parse($row->first_response_at));
        });

        return round((float) $minutes->avg(), 1);
    }
}
