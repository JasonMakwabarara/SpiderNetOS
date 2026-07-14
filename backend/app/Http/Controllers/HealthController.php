<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function index()
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $status = 'healthy';
        foreach ($checks as $key => $value) {
            if ($value !== 'ok') {
                $status = 'unhealthy';
                break;
            }
        }

        return response()->json([
            'status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toISOString(),
        ]);
    }

    private function checkDatabase()
    {
        try {
            DB::connection()->getPdo();
            return 'ok';
        } catch (\Exception $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    private function checkRedis()
    {
        try {
            if (class_exists('Redis')) {
                $redis = Redis::connection();
                $redis->ping();
                return 'ok';
            }
            return 'ok'; // Redis not configured, treat as ok
        } catch (\Exception $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}
