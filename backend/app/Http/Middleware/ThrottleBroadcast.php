<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ThrottleBroadcast
{
    public function handle(Request $request, Closure $next)
    {
        // TODO: Implement tenant-level WebSocket event batching
        // For now, pass through
        return $next($request);
    }
}
