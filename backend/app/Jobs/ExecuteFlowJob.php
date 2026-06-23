<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DagExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $flowId,
        public readonly array $context = [],
        public readonly ?string $userId = null,
    ) {}

    public function handle(DagExecutionService $dagExecution): void
    {
        try {
            $dagExecution->createExecution($this->tenantId, $this->flowId, $this->context);
        } catch (\Throwable $e) {
            Log::error('[ExecuteFlowJob] failed', [
                'tenant_id' => $this->tenantId,
                'flow_id' => $this->flowId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
