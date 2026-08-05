<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Resolve tenant_id from the authenticated user and attach to request.
     * All subsequent queries should be scoped to this tenant.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Authentication required',
            ], 401);
        }

        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'message' => 'User has no associated tenant',
            ], 403);
        }

        // Verify tenant exists and is active
        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant || !$tenant->isActive()) {
            return response()->json([
                'message' => 'Tenant is inactive or does not exist',
            ], 403);
        }

        // Attach tenant_id to request attributes for downstream use
        $request->attributes->set('tenant_id', $tenantId);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
