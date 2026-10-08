<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\Enterprise\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function __construct(private readonly AssetService $assets) {}

    public function index(Request $request): JsonResponse
    {
        $rows = Asset::forTenant((string) $request->attributes->get('tenant_id'))
            ->with('assignments')
            ->orderBy('tag')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tag' => 'required|string|max:64',
            'name' => 'required|string|max:255',
            'acquired_on' => 'nullable|date',
            'cost' => 'nullable|numeric|gt:0',
            'residual_value' => 'nullable|numeric|min:0',
            'useful_life_months' => 'nullable|integer|min:1',
        ]);
        $asset = $this->assets->create((string) $request->attributes->get('tenant_id'), $data);

        return response()->json(['data' => $asset], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $asset = Asset::forTenant((string) $request->attributes->get('tenant_id'))
            ->with('assignments')
            ->findOrFail($id);

        return response()->json(['data' => $asset]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['employee_id' => 'required|uuid']);
        $asset = $this->assets->assign((string) $request->attributes->get('tenant_id'), $id, $data['employee_id']);

        return response()->json(['data' => $asset]);
    }

    public function returnAsset(Request $request, string $id): JsonResponse
    {
        $asset = $this->assets->returnAsset((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $asset]);
    }

    public function repair(Request $request, string $id): JsonResponse
    {
        $asset = $this->assets->markRepair((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $asset]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $asset = $this->assets->restore((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $asset]);
    }

    public function dispose(Request $request, string $id): JsonResponse
    {
        $asset = $this->assets->dispose((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $asset]);
    }

    public function depreciate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['period' => 'required|date_format:Y-m']);
        $entry = $this->assets->depreciate((string) $request->attributes->get('tenant_id'), $id, $data['period']);

        return response()->json(['data' => $entry], 201);
    }
}
