<?php

declare(strict_types=1);

namespace App\Services\Outreach\Ops;

use App\Models\ConversationMessage;
use App\Models\PackEntitlement;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Services\Connectors\ConnectorManager;
use App\Services\FeatureFlag;
use App\Services\Outreach\OutreachSettings;
use Illuminate\Support\Facades\DB;

/**
 * Pre-flight for partner outreach: every condition that has to hold before a
 * tenant may send, reply or create affiliates, each with the one command that
 * fixes it. `outreach:doctor` prints this, `outreach:enable` refuses to lift a
 * flag while a blocking check fails, and the daily digest reuses the stats.
 */
class OutreachHealth
{
    /** Trailing outbound partner emails the bounce rate is measured over. */
    public const BOUNCE_WINDOW = 50;

    /** Above this, sending auto-pauses (mailbox providers filter well before 10%). */
    public const BOUNCE_THRESHOLD = 0.05;

    /** A draft nobody has approved for this long is a stuck conversation. */
    public const STALE_DRAFT_HOURS = 72;

    public const STAGES = ['send', 'bot', 'affiliate'];

    public function __construct(
        private readonly OutreachSettings $settings,
        private readonly ConnectorManager $connectors,
    ) {}

    /**
     * @return array{tenant: array<string, string>, checks: list<array<string, mixed>>,
     *     stats: array<string, mixed>, blocking: array<string, list<string>>, ready: array<string, bool>}
     */
    public function report(Tenant $tenant): array
    {
        $tenantId = (string) $tenant->id;
        $config = $this->settings->for($tenant);
        $program = (array) $config['program'];
        $mail = $this->credentials($tenantId, 'zoho_mail');
        $affonso = $this->credentials($tenantId, 'affonso');
        $has = fn (array $c, string $k) => trim((string) ($c[$k] ?? '')) !== '';
        $checks = [];

        $entitled = PackEntitlement::where('tenant_id', $tenantId)->where('pack_id', 'sales-crm')
            ->where('status', 'active')->exists();
        $checks[] = $this->check('entitlement', $entitled ? 'ok' : 'fail',
            $entitled ? 'sales-crm pack entitled' : 'sales-crm pack not entitled',
            "php artisan spidernet:pack-install sales-crm --tenant={$tenantId} --grant", ['send', 'bot', 'affiliate']);

        $smtp = $has($mail, 'from_address') && $has($mail, 'smtp_password');
        $checks[] = $this->check('mailbox_smtp', $smtp ? 'ok' : 'fail',
            $smtp ? 'sending as '.$mail['from_address'] : 'no partner mailbox connected',
            'Cockpit → Connectors → Zoho Mail: from address + SMTP host/user/password, then Test', ['send', 'bot']);

        $imap = $has($mail, 'imap_password');
        $checks[] = $this->check('mailbox_imap', $imap ? 'ok' : 'fail',
            $imap ? 'IMAP password stored, replies can be polled' : 'no IMAP password: replies would never arrive',
            'Cockpit → Connectors → Zoho Mail: IMAP host/user/app password', ['bot']);

        $join = $has($program, 'join_url');
        $checks[] = $this->check('join_url', $join ? 'ok' : 'fail',
            $join ? 'join link set' : 'program.join_url empty: invites would carry a dead link',
            'Cockpit → Partners → Settings → join URL (Affonso group invite link)', ['send', 'bot']);

        $postal = $has($program, 'postal_address');
        $checks[] = $this->check('postal_address', $postal ? 'ok' : 'fail',
            $postal ? 'postal address set' : 'program.postal_address empty: cold B2B email needs a physical address',
            'Cockpit → Partners → Settings → postal address', ['send']);

        $checks[] = $this->check('affonso_api', $has($affonso, 'api_key') && $has($affonso, 'program_id') ? 'ok' : 'fail',
            $has($affonso, 'api_key') ? 'Affonso API key + program id stored' : 'Affonso connector not configured',
            'Cockpit → Connectors → Affonso: API key, program id, group id, webhook secret', ['affiliate']);

        $secret = $has($affonso, 'webhook_secret');
        $checks[] = $this->check('affonso_webhook', $secret ? 'ok' : 'warn',
            $secret ? 'webhook secret stored: signup events will be accepted' : 'no webhook secret: Affonso signups will not reach SpiderNet',
            'Affonso → Settings → Webhooks: add '.rtrim((string) config('app.url'), '/')."/api/webhooks/affonso/{$tenantId} and paste its signing secret into the connector", []);

        $stats = $this->stats($tenantId);

        $checks[] = $this->check('prospects', $stats['ready'] > 0 ? 'ok' : 'warn',
            $stats['ready'] > 0
                ? $stats['ready'].' prospect(s) ready to receive step 1'
                : $stats['total'].' prospect(s) imported, none with a usable email yet',
            'php artisan outreach:import '.$tenant->slug.' <shortlist.csv>, then add emails in Cockpit → Partners', []);

        $bounce = $stats['bounce'];
        $overBounce = $bounce['rate'] > self::BOUNCE_THRESHOLD;
        $checks[] = $this->check('bounce_rate', $overBounce ? 'fail' : 'ok',
            $bounce['sent'] === 0
                ? 'no sends yet'
                : sprintf('%.1f%% bounced over the last %d sends', $bounce['rate'] * 100, $bounce['sent']),
            'Fix the addresses (or stop guessing them) before resuming; the digest auto-pauses above '
                .(int) (self::BOUNCE_THRESHOLD * 100).'%', ['send']);

        $checks[] = $this->check('stale_drafts', $stats['stale_drafts'] === 0 ? 'ok' : 'warn',
            $stats['stale_drafts'] === 0
                ? 'no drafts waiting over '.self::STALE_DRAFT_HOURS.'h'
                : $stats['stale_drafts'].' bot draft(s) unapproved for over '.self::STALE_DRAFT_HOURS.'h',
            'Cockpit → Approvals: approve, edit or reject them', []);

        $checks[] = $this->check('needs_human', $stats['needs_human'] === 0 ? 'ok' : 'warn',
            $stats['needs_human'] === 0 ? 'no threads waiting on a human' : $stats['needs_human'].' thread(s) waiting on a human',
            'Cockpit → Partners → "Needs a human", reply, then Hand back to bot', []);

        $blocking = [];
        foreach (self::STAGES as $stage) {
            $blocking[$stage] = array_values(array_map(
                fn (array $c) => (string) $c['key'],
                array_filter($checks, fn (array $c) => $c['status'] === 'fail' && in_array($stage, $c['gates'], true)),
            ));
        }

        return [
            'tenant' => [
                'id' => $tenantId, 'slug' => (string) $tenant->slug, 'name' => (string) $tenant->name,
                'automation_level' => (string) $tenant->automation_level, 'reply_mode' => (string) (((array) $config['replies'])['mode'] ?? 'approve'),
            ],
            'checks' => $checks,
            'stats' => $stats,
            'blocking' => $blocking,
            'ready' => array_map(fn (array $keys) => $keys === [], $blocking),
            'flags' => $this->flags($tenantId),
        ];
    }

    /** @return array<string, mixed> */
    public function stats(string $tenantId): array
    {
        $byStatus = PartnerProspect::forTenant($tenantId)->selectRaw('status, count(*) as c')
            ->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all();
        $since = now()->subDay();

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'ready' => (int) ($byStatus[PartnerProspect::STATUS_READY] ?? 0),
            'needs_email' => (int) ($byStatus[PartnerProspect::STATUS_NEEDS_EMAIL] ?? 0),
            'signed_up' => (int) ($byStatus[PartnerProspect::STATUS_SIGNED_UP] ?? 0),
            'needs_human' => PartnerProspect::forTenant($tenantId)->whereNotNull('needs_human_at')->count(),
            'dm_queue' => ConversationMessage::forTenant($tenantId)->where('status', 'awaiting_operator')->count(),
            'sent_24h' => ConversationMessage::forTenant($tenantId)->where('direction', 'out')
                ->where('status', 'sent')->where('created_at', '>=', $since)->count(),
            'replies_24h' => ConversationMessage::forTenant($tenantId)->where('direction', 'in')
                ->where('created_at', '>=', $since)->count(),
            'signups_24h' => PartnerProspect::forTenant($tenantId)->where('signed_up_at', '>=', $since)->count(),
            'unsubscribed_24h' => PartnerProspect::forTenant($tenantId)->where('unsubscribed_at', '>=', $since)->count(),
            'bounced_24h' => PartnerProspect::forTenant($tenantId)->where('bounced_at', '>=', $since)->count(),
            'pending_drafts' => ConversationMessage::forTenant($tenantId)->where('status', 'draft')->count(),
            'stale_drafts' => ConversationMessage::forTenant($tenantId)->where('status', 'draft')
                ->where('created_at', '<', now()->subHours(self::STALE_DRAFT_HOURS))->count(),
            'pending_approvals' => DB::table('approvals')->where('tenant_id', $tenantId)
                ->whereIn('resource_type', ['outreach_reply', 'outreach_thread'])->where('status', 'pending')->count(),
            'bounce' => $this->bounceRate($tenantId),
        ];
    }

    /**
     * Bounce rate over the trailing window of outbound partner emails: of the
     * distinct prospects we last wrote to, how many hard-bounced. Counting
     * prospects rather than messages stops one dead address that received three
     * steps from looking like three bounces.
     *
     * @return array{sent: int, bounced: int, rate: float, window: int}
     */
    public function bounceRate(string $tenantId, int $window = self::BOUNCE_WINDOW): array
    {
        // Every column is qualified: conversations carries tenant_id and status too.
        $leadIds = ConversationMessage::query()
            ->where('conversation_messages.tenant_id', $tenantId)
            ->where('conversation_messages.direction', 'out')
            ->where('conversation_messages.status', 'sent')
            ->where('conversation_messages.template_key', 'like', 'partner.%')
            ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
            ->orderByDesc('conversation_messages.created_at')
            ->limit($window)
            ->pluck('conversations.lead_id')
            ->unique()->values()->all();

        $sent = count($leadIds);
        if ($sent === 0) {
            return ['sent' => 0, 'bounced' => 0, 'rate' => 0.0, 'window' => $window];
        }

        $bounced = PartnerProspect::forTenant($tenantId)->whereIn('lead_id', $leadIds)
            ->whereNotNull('bounced_at')->count();

        return ['sent' => $sent, 'bounced' => $bounced, 'rate' => round($bounced / $sent, 4), 'window' => $window];
    }

    /** @return array<string, bool> */
    public function flags(string $tenantId): array
    {
        $flags = [];
        foreach (['enabled', 'sending', 'inbound_poll', 'bot_replies', 'affonso_actions', 'digest', 'finder_sync', 'finder_writeback'] as $flag) {
            $flags[$flag] = FeatureFlag::on('outreach.'.$flag, $tenantId);
        }

        return $flags;
    }

    /**
     * Stored connector credentials for a provider, or [] when not connected.
     *
     * @return array<string, mixed>
     */
    public function credentials(string $tenantId, string $provider): array
    {
        $integration = TenantIntegration::forTenant($tenantId)->where('provider', $provider)
            ->where('is_active', true)->first();

        if ($integration === null) {
            return [];
        }

        return $this->connectors->credentialsFor($integration) + (array) ($integration->config ?? []);
    }

    /**
     * @param  list<string>  $gates  stages this check blocks when it fails
     * @return array{key: string, status: string, detail: string, fix: ?string, gates: list<string>}
     */
    private function check(string $key, string $status, string $detail, ?string $fix, array $gates): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'detail' => $detail,
            'fix' => $status === 'ok' ? null : $fix,
            'gates' => $gates,
        ];
    }
}
