<?php

namespace App\Console\Commands;

use App\Services\ReplayDivergenceService;
use Illuminate\Console\Command;

class ReplayDetectDivergence extends Command
{
    protected $signature = 'events:detect-divergence {tenant : Tenant UUID} {execution : Execution UUID}';
    protected $description = 'Run temporal-grade replay and produce divergence report';

    public function handle(ReplayDivergenceService $service): int
    {
        $tenantId = (string) $this->argument('tenant');
        $executionId = (string) $this->argument('execution');

        try {
            $report = $service->detectDivergence($tenantId, $executionId);
            $this->info(json_encode($report, JSON_PRETTY_PRINT));
            return ($report['status'] ?? 'clean') === 'clean' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
