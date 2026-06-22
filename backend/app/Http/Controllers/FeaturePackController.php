<?php

namespace App\Http\Controllers;

use App\Models\FeaturePack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

class FeaturePackController extends Controller
{
    public function catalogue(): JsonResponse
    {
        $root = env('FEATURE_PACKS_ROOT', dirname(base_path()).'/packages/feature-packs');
        $entries = [];

        if (is_dir($root)) {
            foreach (glob($root.'/*/pack.yaml') ?: [] as $path) {
                try {
                    $yaml = Yaml::parseFile($path);
                    $meta = $yaml['metadata'] ?? [];
                    $entries[] = [
                        'pack_id' => $meta['id'] ?? basename(dirname($path)),
                        'version' => $meta['version'] ?? '0.0.0',
                        'vertical' => $meta['vertical'] ?? 'general',
                        'display_name' => $meta['displayName'] ?? $meta['display_name'] ?? $meta['id'] ?? basename(dirname($path)),
                        'description' => $meta['description'] ?? '',
                        'installable' => true,
                    ];
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return response()->json(['data' => $entries]);
    }

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
