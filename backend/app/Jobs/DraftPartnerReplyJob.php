<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Services\Outreach\Bot\RecruiterBot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Draft (or, in auto mode, send) the recruiter bot's answer to one inbound message. */
class DraftPartnerReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $prospectId,
        public readonly string $inboundMessageId,
    ) {}

    public function handle(RecruiterBot $bot): void
    {
        $tenant = Tenant::find($this->tenantId);
        $prospect = PartnerProspect::forTenant($this->tenantId)->find($this->prospectId);
        $inbound = ConversationMessage::forTenant($this->tenantId)->find($this->inboundMessageId);

        if ($tenant === null || $prospect === null || $inbound === null) {
            Log::warning('DraftPartnerReplyJob: missing tenant/prospect/message', ['tenant_id' => $this->tenantId, 'prospect_id' => $this->prospectId]);

            return;
        }

        $result = $bot->draft($tenant, $prospect, $inbound);
        Log::info('outreach bot', ['prospect_id' => $prospect->id, 'outcome' => $result['outcome'], 'reason' => $result['reason'] ?? null]);
    }
}
