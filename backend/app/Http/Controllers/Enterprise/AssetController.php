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
}
