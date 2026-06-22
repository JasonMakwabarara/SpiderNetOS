<?php

namespace App\Http\Controllers;

use App\Models\FeaturePack;
use App\Services\FeaturePackInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

class FeaturePackController extends Controller
{
    /** Customer-facing outcome bullets keyed by pack_id. */
    private const PACK_OUTCOMES = [
        'financial-services' => [
            'Know who owes you money and chase overdue invoices',
            'See cash position without opening a spreadsheet',
            'Get flagged before a large payment needs approval',
        ],
        'sales-crm' => [
            'Never lose a lead because follow-up was late',
            'See your pipeline and what needs action today',
            'Win back customers before they churn',
        ],
        'real-estate-crm' => [
            'Capture and qualify property leads automatically',
            'Schedule viewings without back-and-forth',
            'Track offers from submission to close',
        ],
        'compliance-radar' => [
            'Discover what compliance applies to your business',
            'Plain-English obligations — no jargon required',
            'One action per topic, not an overwhelming checklist',
        ],
    ];

    public function catalogue(): JsonResponse
    {
        $root = env('FEATURE_PACKS_ROOT', dirname(base_path()).'/packages/feature-packs');
        $entries = [];

        if (is_dir($root)) {
            foreach (glob($root.'/*/pack.yaml') ?: [] as $path) {
                try {
                    $yaml = Yaml::parseFile($path);
                    $meta = $yaml['metadata'] ?? [];
                    $packId = $meta['id'] ?? basename(dirname($path));
                    $agents = $yaml['spec']['provides']['dynamic_agents'] ?? [];
                    $flows = $yaml['spec']['provides']['flows'] ?? [];

                    $entries[] = [
                        'pack_id' => $packId,
                        'version' => $meta['version'] ?? '0.0.0',
                        'vertical' => $meta['vertical'] ?? 'general',
                        'display_name' => $meta['displayName'] ?? $meta['display_name'] ?? $packId,
                        'description' => $meta['description'] ?? '',
                        'installable' => true,
                        'agent_count' => count($agents),
                        'flow_count' => count($flows),
                        'customer_outcomes' => $meta['customer_outcomes'] ?? self::PACK_OUTCOMES[$packId] ?? [],
                        'entry_path' => $this->entryPath($packId),
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
                'entry_path' => $this->entryPath($pack->pack_id),
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
                'entry_path' => $this->entryPath($pack->pack_id),
            ],
        ]);
    }

    /**
     * POST /api/feature-packs/{id}/install
     * Response: { data: { pack_id, version, display_name, status, agents_provisioned, entry_path } }
     */
    public function install(Request $request, string $id, FeaturePackInstaller $installer): JsonResponse
    {
        $tenant = $request->user()->tenant;

        try {
            $result = $installer->install($tenant, $id, (bool) $request->boolean('force'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(['data' => $result]);
    }

    private function entryPath(string $packId): string
    {
        return match ($packId) {
            'financial-services' => '/financial',
            'sales-crm' => '/sales',
            'compliance-radar' => '/compliance',
            default => '/feature-packs',
        };
    }
}
