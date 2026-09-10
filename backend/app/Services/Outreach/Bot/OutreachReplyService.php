<?php

declare(strict_types=1);

namespace App\Services\Outreach\Bot;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Services\ApprovalEngine;
use App\Services\Connectors\ConnectorManager;
use App\Services\EventStore;
use App\Services\FeatureFlag;
use App\Services\Outreach\Affonso\AffonsoClient;
use App\Services\Outreach\OutreachSender;
use App\Services\Outreach\ProspectStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What happens around a bot draft: the approval request, delivery once a
 * human (or auto mode) says go, the draft's side effect (signed up, opt-out,
 * decline, create the affiliate via Affonso), and handoffs to a human.
 */
class OutreachReplyService
{
    public function __construct(
        private readonly OutreachSender $sender,
        private readonly ProspectStateMachine $lifecycle,
        private readonly ApprovalEngine $approvals,
        private readonly ConnectorManager $connectors,
        private readonly EventStore $events,
    ) {}

    /** @return array<string, mixed> the approval record */
    public function requestApproval(Tenant $tenant, PartnerProspect $prospect, ConversationMessage $draft, ConversationMessage $inbound): array
    {
        $who = $prospect->display_name ?: ($prospect->handle ? '@'.$prospect->handle : 'prospect');
        $meta = (array) $draft->draft_meta;

        return $this->approvals->createApproval(
            (string) $tenant->id,
            $this->requesterId($tenant),
            'outreach_reply',
            'outreach_reply',
            (string) $draft->id,
            "Reply to {$who}: ".mb_substr((string) $draft->body, 0, 140),
            [
                'prospect_id' => $prospect->id,
                'conversation_id' => $draft->conversation_id,
                'channel' => 'email',
                'action' => $draft->draft_action,
                'display_name' => $prospect->display_name,
                'handle' => $prospect->handle,
                'platform' => $prospect->platform,
                'draft_body' => $draft->body,
                'inbound_excerpt' => mb_substr((string) $inbound->body, 0, 500),
                'extracted' => $meta['extracted'] ?? [],
                'confidence' => $meta['confidence'] ?? null,
                'risk' => $draft->draft_action === 'create_affiliate' ? 'high' : 'low',
            ],
        );
    }

    /** Approval outcome for an outreach_reply draft (single-stage or chained). */
    public function onApprovalResolved(string $tenantId, string $draftId, bool $granted, string $response = ''): void
    {
        $draft = ConversationMessage::forTenant($tenantId)->find($draftId);
        $tenant = Tenant::find($tenantId);
        if ($draft === null || $tenant === null || $draft->status !== 'draft') {
            return;
        }

        if (! $granted) {
            $draft->update(['status' => 'rejected', 'draft_meta' => ((array) $draft->draft_meta) + ['rejected_reason' => mb_substr($response, 0, 500)]]);
            if ($prospect = $this->prospectFor($draft)) {
                $this->flagHuman($prospect, 'draft_rejected');
            }

            return;
        }

        $this->deliver($tenant, $draft);
    }

    /**
     * Send a draft's current body (an operator may have edited it) and apply
     * its action.
     *
     * @return array{success: bool, error?: string}
     */
    public function deliver(Tenant $tenant, ConversationMessage $draft): array
    {
        $prospect = $this->prospectFor($draft);
        if ($prospect === null) {
            return ['success' => false, 'error' => 'no_prospect'];
        }

        $result = $this->sender->sendOperatorReply($tenant, $prospect, (string) $draft->body, $draft->subject, RecruiterBot::SENT_BY);
        if (! $result['success']) {
            $draft->update(['status' => 'failed', 'error' => mb_substr((string) ($result['error'] ?? 'send failed'), 0, 500)]);

            return ['success' => false, 'error' => (string) ($result['error'] ?? 'send failed')];
        }

        $draft->update(['status' => 'approved', 'draft_meta' => ((array) $draft->draft_meta) + [
            'sent_message_id' => isset($result['message']) ? $result['message']->id : null, 'sent_at' => now()->toIso8601String(),
        ]]);

        $this->applyAction($tenant, $prospect->refresh(), $draft);

        return ['success' => true];
    }

    public function applyAction(Tenant $tenant, PartnerProspect $prospect, ConversationMessage $draft): void
    {
        $meta = (array) $draft->draft_meta;
        $extracted = (array) ($meta['extracted'] ?? []);
        $nonTerminal = array_values(array_diff(PartnerProspect::STATUSES, PartnerProspect::TERMINAL));

        $lead = $prospect->lead;
        if (! empty($extracted['email']) && $lead !== null && empty($lead->email)) {
            $this->lifecycle->acceptEmail($prospect, (string) $extracted['email'], 'bot');
            $prospect->refresh();
        }

        switch ((string) $draft->draft_action) {
            case 'signed_up':
                $this->lifecycle->transition($prospect, PartnerProspect::STATUS_SIGNED_UP, ['signed_up_at' => now(), 'next_send_at' => null], $nonTerminal);
                break;
            case 'unsubscribe':
                $this->lifecycle->optOut($prospect, 'bot_request', ['draft_id' => $draft->id]);
                break;
            case 'decline_close':
                $this->lifecycle->transition($prospect, PartnerProspect::STATUS_DECLINED, ['declined_at' => now(), 'next_send_at' => null], $nonTerminal);
                break;
            case 'create_affiliate':
                $this->createAffiliate($tenant, $prospect, $draft);
                break;
        }
    }

    private function createAffiliate(Tenant $tenant, PartnerProspect $prospect, ConversationMessage $draft): void
    {
        $tenantId = (string) $tenant->id;

        if (! FeatureFlag::on('outreach.affonso_actions', $tenantId)) {
            $this->flagHuman($prospect, 'affonso_actions_off');

            return;
        }
        $integration = TenantIntegration::forTenant($tenantId)->where('provider', 'affonso')->where('is_active', true)->first();
        if ($integration === null) {
            $this->flagHuman($prospect, 'affonso_not_connected');

            return;
        }
        $lead = $prospect->lead;
        if ($lead === null || empty($lead->email)) {
            $this->flagHuman($prospect, 'no_email_for_affiliate');

            return;
        }

        $credentials = $this->connectors->credentialsFor($integration) + (array) ($integration->config ?? []);

        try {
            $result = AffonsoClient::fromCredentials($credentials)->createAffiliate(
                (string) ($prospect->display_name ?: $lead->name ?: $lead->email),
                (string) $lead->email,
                [
                    'external_user_id' => (string) $lead->id,
                    'group_id' => $credentials['group_id'] ?? null,
                    'metadata' => ['prospect_token' => $prospect->invite_token, 'spidernet_tenant_id' => $tenantId, 'source' => 'spidernet_outreach'],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('affonso create affiliate failed', ['prospect_id' => $prospect->id, 'error' => $e->getMessage()]);
            $this->flagHuman($prospect, 'affonso_error');

            return;
        }

        $affiliate = (array) $result['affiliate'];
        $status = strtoupper((string) ($affiliate['partnership_status'] ?? 'PENDING'));
        PartnerProspect::whereKey($prospect->id)->update([
            'affonso_affiliate_id' => $affiliate['id'] ?? null,
            'affonso_tracking_id' => $affiliate['tracking_id'] ?? null,
            'affiliate_status' => $status,
        ]);
        $this->events->append($tenantId, 'partner_prospect', (string) $prospect->id, 'outreach.affiliate.created', [
            'prospect_id' => $prospect->id, 'affiliate_id' => $affiliate['id'] ?? null, 'created' => (bool) $result['created'], 'via' => 'api',
        ]);

        // Created through the API means the creator asked us to; that is a signup.
        $this->lifecycle->transition($prospect->refresh(), PartnerProspect::STATUS_SIGNED_UP, ['signed_up_at' => now(), 'next_send_at' => null],
            array_values(array_diff(PartnerProspect::STATUSES, PartnerProspect::TERMINAL)));
    }

    /**
     * Park the thread with a human: prospect handoff + an escalation approval.
     *
     * @param  array<string, mixed>  $meta
     * @return array{outcome: string, reason: string, approval_id: string}
     */
    public function handoff(Tenant $tenant, PartnerProspect $prospect, ConversationMessage $inbound, string $reason, array $meta = []): array
    {
        $tenantId = (string) $tenant->id;
        $from = array_values(array_diff(PartnerProspect::STATUSES, PartnerProspect::TERMINAL, [PartnerProspect::STATUS_HANDOFF]));

        $this->lifecycle->transition($prospect, PartnerProspect::STATUS_HANDOFF, [
            'needs_human_at' => now(), 'needs_human_reason' => mb_substr($reason, 0, 190), 'bot_paused_at' => now(), 'next_send_at' => null,
        ], $from);

        $who = $prospect->display_name ?: ($prospect->handle ? '@'.$prospect->handle : 'prospect');
        $approval = $this->approvals->createApproval($tenantId, $this->requesterId($tenant), 'escalation', 'outreach_thread', (string) $inbound->conversation_id,
            "Recruiter bot needs a human for {$who}: {$reason}",
            ['prospect_id' => $prospect->id, 'conversation_id' => $inbound->conversation_id, 'reason' => $reason,
                'inbound_excerpt' => mb_substr((string) $inbound->body, 0, 500), 'risk' => 'high'] + $meta);

        return ['outcome' => 'handoff', 'reason' => $reason, 'approval_id' => (string) ($approval['id'] ?? '')];
    }

    /** A human dealt with it: resume the bot on this thread. */
    public function handBack(PartnerProspect $prospect): bool
    {
        return $this->lifecycle->transition($prospect, PartnerProspect::STATUS_NEGOTIATING, [
            'needs_human_at' => null, 'needs_human_reason' => null, 'bot_paused_at' => null,
        ], [PartnerProspect::STATUS_HANDOFF]);
    }

    private function requesterId(Tenant $tenant): string
    {
        $agentId = DB::table('agents')->where('tenant_id', $tenant->id)->where('slug', 'sales_crm_crm')->value('id');

        return (string) ($agentId ?: $tenant->id);
    }

    private function prospectFor(ConversationMessage $draft): ?PartnerProspect
    {
        $leadId = Conversation::where('id', $draft->conversation_id)->value('lead_id');

        return $leadId ? PartnerProspect::forTenant((string) $draft->tenant_id)->where('lead_id', $leadId)->with('lead')->first() : null;
    }

    private function flagHuman(PartnerProspect $prospect, string $reason): void
    {
        PartnerProspect::whereKey($prospect->id)->update(['needs_human_at' => now(), 'needs_human_reason' => mb_substr($reason, 0, 190)]);
    }
}
