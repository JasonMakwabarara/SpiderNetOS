<?php

namespace App\Jobs;

use App\Services\EventStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OpsDivergenceAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $executionId,
        private readonly string $reportId,
        private readonly int $divergenceCount
    ) {}

    public function handle(EventStore $eventStore): void
    {
        $alert = [
            'tenant_id' => $this->tenantId,
            'execution_id' => $this->executionId,
            'report_id' => $this->reportId,
            'divergence_count' => $this->divergenceCount,
            'severity' => $this->divergenceCount > 5 ? 'high' : 'medium',
        ];

        Log::channel('stack')->warning('Ops divergence alert', $alert);

        $eventStore->append(
            tenantId: $this->tenantId,
            aggregateType: 'ops_alert',
            aggregateId: $this->reportId,
            eventType: 'ops.divergence_alerted',
            payload: $alert,
            metadata: ['source' => 'replay_divergence_sweep']
        );
    }
}
