<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckAgentPermission
{
    public function handle(Request $request, Closure $next)
    {
        // TODO: Implement graph-based agent permission check
        // For now, pass through
        return $next($request);
    }
}
