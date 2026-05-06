<?php

namespace App\Http\Controllers;

use App\Models\FeaturePack;
use Illuminate\Http\Request;

class FeaturePackController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->tenant->featurePacks();

        if ($request->filled('vertical')) {
            $query->where('vertical', $request->query('vertical'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $packs = $query->get()->map(function (FeaturePack $pack) {
            return [
                'id' => $pack->id,
                'pack_id' => $pack->pack_id,
                'version' => $pack->version,
                'vertical' => $pack->vertical,
                'display_name' => $pack->display_name,
                'description' => $pack->description,
                'status' => $pack->status,
                'agents' => $pack->agents,
                'flows' => $pack->flows,
                'installed_at' => $pack->installed_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $packs]);
    }

    public function show(Request $request, string $id)
    {
        $pack = $request->user()->tenant->featurePacks()
            ->where('pack_id', $id)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $pack->id,
                'pack_id' => $pack->pack_id,
                'version' => $pack->version,
                'vertical' => $pack->vertical,
                'display_name' => $pack->display_name,
                'description' => $pack->description,
                'status' => $pack->status,
                'manifest' => $pack->manifest,
                'agents' => $pack->agents,
                'flows' => $pack->flows,
                'installed_at' => $pack->installed_at?->toIso8601String(),
            ],
        ]);
    }
}
