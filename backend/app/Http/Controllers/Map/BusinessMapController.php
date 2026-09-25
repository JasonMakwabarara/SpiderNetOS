<?php

declare(strict_types=1);

namespace App\Http\Controllers\Map;

use App\Http\Controllers\Controller;
use App\Services\Map\BusinessMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The business map (plan D6-C): GET /api/map composes the systemization map,
 * the skills catalogue with the tenant's state and brain readiness into the
 * core + nine pillars + nodes the cockpit's radial map renders.
 */
class BusinessMapController extends Controller
{
    public function __construct(
        private readonly BusinessMapService $map,
    ) {}

    /** GET /api/map */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->map->map($this->tenantId($request), $request->user()),
        ]);
    }

    /**
     * GET /api/map/nodes/{id} — a skill node id (sales-outreach-writing) or a
     * business system uuid. BusinessMapService::node() only touches the uuid
     * column after Str::isUuid() (Postgres aborts the transaction on a bad uuid).
     */
    public function node(Request $request, string $id): JsonResponse
    {
        $node = $this->map->node($this->tenantId($request), $id);

        if ($node === null) {
            return response()->json(['message' => 'Map node not found.'], 404);
        }

        return response()->json(['data' => $node]);
    }

    private function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('tenant_id');
    }
}
