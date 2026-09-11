<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Outreach\Inbound\InboxPoller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every two minutes: poll each outreach tenant's partner mailbox.
 * Per-tenant isolation; the poller self-gates on outreach.inbound_poll.
 */
class PollPartnerMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(InboxPoller $poller): void
    {
        foreach ($poller->tenants() as $tenant) {
            try {
                $result = $poller->pollTenant($tenant);
                if ($result['fetched'] > 0) {
                    Log::info('outreach inbox poll', $result);
                }
            } catch (\Throwable $e) {
                Log::warning('outreach inbox poll failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
