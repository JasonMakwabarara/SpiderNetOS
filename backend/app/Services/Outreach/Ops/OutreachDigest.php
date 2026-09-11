<?php

declare(strict_types=1);

namespace App\Services\Outreach\Ops;

use App\Models\Tenant;
use App\Services\ApprovalEngine;
use App\Services\EventStore;
use App\Services\FeatureFlag;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily outreach digest for tenant admins, and the safety valve that comes with
 * it: when the trailing bounce rate crosses the threshold on a big enough
 * sample, sending is switched off for that tenant and a human is asked to look.
 * Deterministic — no model involved.
 */
class OutreachDigest
{
    /** Below this many sends in the window, one bad address is not a trend. */
    public const MIN_SAMPLE = 20;

    public function __construct(
        private readonly OutreachHealth $health,
        private readonly ApprovalEngine $approvals,
        private readonly NotificationService $notifications,
        private readonly EventStore $events,
    ) {}

    /**
     * @return array{tenant_id: string, skipped: ?string, paused: bool, pause_reason: ?string, notified: int,
     *     stats: array<string, mixed>, lines: list<string>, dry_run: bool}
     */
    public function run(Tenant $tenant, bool $dryRun = false, bool $force = false): array
    {
        $tenantId = (string) $tenant->id;

        if (! $force && ! FeatureFlag::on('outreach.digest', $tenantId)) {
            return $this->result($tenantId, $dryRun, 'flag_off');
        }

        $stats = $this->health->stats($tenantId);
        $lines = $this->lines($stats);
        $pause = $this->shouldPause($tenantId, $stats);

        if ($pause !== null && ! $dryRun) {
            $this->pauseSending($tenant, $pause, $stats);
        }

        $notified = 0;
        if (! $dryRun) {
            $notified = $this->notify($tenant, $stats, $lines, $pause);
            $this->events->append($tenantId, 'tenant', $tenantId, 'outreach.digest.sent', [
                'stats' => array_diff_key($stats, ['by_status' => null]),
                'paused' => $pause !== null,
            ]);
        }

        return $this->result($tenantId, $dryRun, null, $pause, $notified, $stats, $lines);
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private function result(string $tenantId, bool $dryRun, ?string $skipped, ?string $pause = null,
        int $notified = 0, array $stats = [], array $lines = []): array
    {
        return [
            'tenant_id' => $tenantId,
            'skipped' => $skipped,
            'paused' => $pause !== null,
            'pause_reason' => $pause,
            'notified' => $notified,
            'stats' => $stats,
            'lines' => $lines,
            'dry_run' => $dryRun,
        ];
    }

    /** The reason sending should stop, or null to carry on. */
    public function shouldPause(string $tenantId, array $stats): ?string
    {
        if (! FeatureFlag::on('outreach.sending', $tenantId)) {
            return null; // already off; nothing to pause
        }

        $bounce = (array) $stats['bounce'];
        if ((int) $bounce['sent'] < self::MIN_SAMPLE) {
            return null;
        }

        if ((float) $bounce['rate'] > OutreachHealth::BOUNCE_THRESHOLD) {
            return sprintf(
                'bounce rate %.1f%% over the last %d sends (threshold %.0f%%)',
                $bounce['rate'] * 100, $bounce['sent'], OutreachHealth::BOUNCE_THRESHOLD * 100,
            );
        }

        return null;
    }

    /** Flip the tenant's sending flag off and raise an escalation a human must clear. */
    private function pauseSending(Tenant $tenant, string $reason, array $stats): void
    {
        $tenantId = (string) $tenant->id;

        try {
            FeatureFlag::set('outreach.sending', 'off', $tenantId);
        } catch (\Throwable $e) {
            // Redis down: say so loudly rather than pretending sending stopped.
            Log::error('outreach.autopause.flag_failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            $reason .= ' — COULD NOT SET THE FLAG, stop sending manually';
        }

        $this->events->append($tenantId, 'tenant', $tenantId, 'outreach.sending.auto_paused', [
            'reason' => $reason, 'bounce' => $stats['bounce'],
        ]);

        $this->approvals->createApproval(
            $tenantId,
            $tenantId,
            'escalation',
            'outreach_sending',
            $tenantId,
            'Outreach sending auto-paused: '.$reason,
            [
                'reason' => $reason,
                'bounce' => $stats['bounce'],
                'risk' => 'high',
                'resume' => 'php artisan outreach:enable send --tenant='.$tenant->slug,
            ],
        );
    }

    /** @return int admins notified */
    private function notify(Tenant $tenant, array $stats, array $lines, ?string $pause): int
    {
        $recipients = DB::table('users')->where('tenant_id', $tenant->id)->whereIn('role', ['admin', 'owner'])->count();

        $this->notifications->notifyTenantRole((string) $tenant->id, ['admin', 'owner'], 'outreach_digest', [
            'title' => $pause === null
                ? 'Partner outreach: yesterday in numbers'
                : 'Partner outreach PAUSED: '.$pause,
            'body' => implode("\n", $lines),
            'severity' => $pause === null ? 'info' : 'critical',
            'stats' => array_diff_key($stats, ['by_status' => null]),
            'url' => '/sales/partners',
        ]);

        return $recipients;
    }

    /** @return list<string> */
    public function lines(array $stats): array
    {
        $bounce = (array) $stats['bounce'];
        $lines = [
            sprintf('Sent %d · replies %d · signups %d', $stats['sent_24h'], $stats['replies_24h'], $stats['signups_24h']),
            sprintf('Bounced %d · unsubscribed %d', $stats['bounced_24h'], $stats['unsubscribed_24h']),
            $bounce['sent'] === 0
                ? 'Bounce rate: no sends yet'
                : sprintf('Bounce rate %.1f%% over the last %d sends', $bounce['rate'] * 100, $bounce['sent']),
            sprintf('Pipeline: %d ready · %d awaiting an email · %d signed up', $stats['ready'], $stats['needs_email'], $stats['signed_up']),
        ];

        if ($stats['pending_approvals'] > 0 || $stats['pending_drafts'] > 0) {
            $lines[] = sprintf('Waiting on you: %d approval(s), %d draft(s)%s',
                $stats['pending_approvals'], $stats['pending_drafts'],
                $stats['stale_drafts'] > 0 ? ' — '.$stats['stale_drafts'].' over '.OutreachHealth::STALE_DRAFT_HOURS.'h old' : '');
        }
        if ($stats['needs_human'] > 0) {
            $lines[] = $stats['needs_human'].' thread(s) handed off to a human';
        }
        if ($stats['dm_queue'] > 0) {
            $lines[] = $stats['dm_queue'].' DM draft(s) in the queue to send by hand';
        }

        return $lines;
    }
}
