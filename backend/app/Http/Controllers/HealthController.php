<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function index()
    {
        $checks = [
            'api' => 'ok',
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'inference' => $this->checkInference(),
        ];

        $allHealthy = !in_array('error', $checks, true);

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'version' => '3.2.0',
            'plane' => 'control',
            'checks' => $checks,
        ], $allHealthy ? 200 : 503);
    }

    private function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();
            return 'ok';
        } catch (\Exception $e) {
            return 'error';
        }
    }

    private function checkRedis(): string
    {
        try {
            Redis::ping();
            return 'ok';
        } catch (\Exception $e) {
            return 'error';
        }
    }

    private function checkInference(): string
    {
        try {
            $url = config('services.inference.url') . '/health';
            $response = \Illuminate\Support\Facades\Http::timeout(3)->get($url);
            return $response->successful() ? 'ok' : 'error';
        } catch (\Exception $e) {
            return 'error';
        }
    }
}
