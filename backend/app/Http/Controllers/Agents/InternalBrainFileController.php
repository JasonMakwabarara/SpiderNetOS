<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Services\Agents\Collaborators;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/internal/brain/files/{path} — a Knowledge-brain read for the
 * Python plane (internal.key + X-Tenant-Id). Head version only; the run's
 * pinned snapshot is what AgentRunner hands the model.
 */
class InternalBrainFileController extends Controller
{
    public function show(Request $request, string $path): JsonResponse
    {
        $tenantId = (string) $request->header('X-Tenant-Id', '');
        if ($tenantId === '') {
            return response()->json(['message' => 'X-Tenant-Id header is required.'], 422);
        }

        $path = trim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return response()->json(['message' => 'Invalid brain path.'], 422);
        }

        $file = Collaborators::readBrainPath($tenantId, $path);
        if ($file === null) {
            return response()->json(['message' => 'Brain file not found.', 'path' => $path], 404);
        }

        return response()->json(['data' => $file]);
    }
}
