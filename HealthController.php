<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $status = 'healthy';
        $checks = [];

        try {
            DB::select('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $status = 'unhealthy';
            $checks['database'] = $e->getMessage();
        }

        try {
            Redis::connection()->ping();
            $checks['redis'] = 'ok';
        } catch (\Exception $e) {
            $status = 'unhealthy';
            $checks['redis'] = $e->getMessage();
        }

        return response()->json(['status' => $status, 'checks' => $checks],
            $status === 'healthy' ? 200 : 503);
    }
}
