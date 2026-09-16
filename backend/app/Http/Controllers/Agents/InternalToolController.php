<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use App\Services\Agents\RunContextFactory;
use App\Services\Tools\ToolCatalogue;
use App\Services\Tools\ToolGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * /api/internal/tools/* — the tool gateway for the Python plane and the
 * MCP server (plan D4, second transport). Guarded by internal.key; tenant
 * scope via X-Tenant-Id. Every call still goes through ToolGateway with
 * the run's own allowlist, ladder and budget.
 */
class InternalToolController extends Controller
{
    public function __construct(
        private readonly ToolCatalogue $catalogue,
        private readonly ToolGateway $gateway,
        private readonly RunContextFactory $contexts,
    ) {}

    public function schema(Request $request): JsonResponse
    {
        $tools = (string) $request->query('tools', '');
        $allowlist = $tools !== '' ? array_values(array_filter(array_map('trim', explode(',', $tools)))) : null;

        return response()->json(['data' => [
            'tools' => $this->catalogue->jsonSchemas($allowlist),
            'mcp' => $this->catalogue->mcpManifest($allowlist),
            'core' => ToolCatalogue::CORE,
        ]]);
    }

    public function execute(Request $request, string $name): JsonResponse
    {
        $tenantId = (string) $request->header('X-Tenant-Id', '');
        if ($tenantId === '') {
            return response()->json(['message' => 'X-Tenant-Id header is required.'], 422);
        }

        $validated = $request->validate([
            'run_id' => 'required|string',
            'params' => 'sometimes|array',
        ]);

        $runId = (string) $validated['run_id'];
        $run = Str::isUuid($runId) ? AgentRun::forTenant($tenantId)->find($runId) : null;
        if ($run === null) {
            return response()->json(['message' => 'Run not found.'], 404);
        }

        try {
            $ctx = $this->contexts->forRun($run, null, rebuildSnapshot: true);
        } catch (AgentRuntimeException $e) {
            return response()->json($e->toResponse(), $e->httpStatus);
        }

        $result = $this->gateway->call($ctx, $name, (array) ($validated['params'] ?? []));

        $status = match (true) {
            ! empty($result['success']) => 200,
            ! empty($result['awaiting_approval']) => 202,
            ! empty($result['denied']) => 403,
            default => 422,
        };

        return response()->json(['data' => $result + ['tool' => $name, 'run_id' => $run->id]], $status);
    }
}
