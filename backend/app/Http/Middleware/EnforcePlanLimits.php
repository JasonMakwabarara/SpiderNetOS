<?php

namespace App\Http\Middleware;

use App\Services\CostGovernor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePlanLimits
{
    private CostGovernor $costGovernor;

    public function __construct(CostGovernor $costGovernor)
    {
        $this->costGovernor = $costGovernor;
    }

    /**
     * Enforce CostGovernor plan limits (Hard Rule #4).
     * If budget exceeded: return 429.
     * If degraded: set request attribute for downstream handlers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (! $tenantId) {
            // No tenant resolved yet — pass through (ResolveTenant runs first)
            return $next($request);
        }

        $costStatus = $this->costGovernor->canExecute($tenantId);

        if (! $costStatus['allowed']) {
            return response()->json([
                'message' => 'Budget exceeded — request blocked by CostGovernor',
                'cost_status' => [
                    'daily_remaining' => $costStatus['daily_remaining'],
                    'monthly_remaining' => $costStatus['monthly_remaining'],
                    'daily_limit' => $costStatus['daily_limit'],
                    'monthly_limit' => $costStatus['monthly_limit'],
                ],
            ], 429);
        }

        // If degraded mode, flag the request
        if ($costStatus['degraded']) {
            $request->attributes->set('degraded', true);
            $request->attributes->set('cost_status', $costStatus);
        }

        return $next($request);
    }
}
