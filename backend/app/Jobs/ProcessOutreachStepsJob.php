<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Outreach\OutreachSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every minute (routes/console.php): one outreach tick per tenant
 * that has outreach configured. Each tenant is isolated so one failure never
 * stalls the others; the sender self-gates on the outreach.sending flag.
 */
class ProcessOutreachStepsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(OutreachSender $sender): void
    {
        foreach ($sender->tenants() as $tenant) {
            try {
                $result = $sender->runForTenant($tenant);

                if ($result['sent'] > 0 || $result['drafted'] > 0 || $result['retired'] > 0 || $result['failed'] > 0) {
                    Log::info('outreach tick', $result);
                }
            } catch (\Throwable $e) {
                Log::error('outreach tick failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
