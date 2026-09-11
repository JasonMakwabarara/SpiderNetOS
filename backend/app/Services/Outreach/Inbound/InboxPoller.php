<?php

declare(strict_types=1);

namespace App\Services\Outreach\Inbound;

use App\Models\Tenant;
use App\Services\FeatureFlag;
use App\Services\Messaging\TenantMailerFactory;
use App\Services\Outreach\OutreachSettings;
use Illuminate\Support\Collection;

/**
 * One poll of a tenant's partner mailbox: fetch what arrived since the last
 * UID, hand each message to the ingestor, remember the high-water mark.
 * Idempotent by construction (Message-ID dedupe), so re-reading is harmless.
 */
class InboxPoller
{
    public function __construct(
        private readonly ImapMailboxReader $reader,
        private readonly TenantMailerFactory $mailers,
        private readonly OutreachSettings $settings,
        private readonly InboundIngestor $ingestor,
    ) {}

    /** Active tenants with outreach configured. @return Collection<int, Tenant> */
    public function tenants(): Collection
    {
        return Tenant::where('status', 'active')->get()
            ->filter(fn (Tenant $t) => isset(((array) $t->settings)[OutreachSettings::KEY]))
            ->values();
    }

    /**
     * @return array{tenant_id: string, skipped: ?string, fetched: int, actions: array<string, int>, last_uid: ?int, dry_run: bool}
     */
    public function pollTenant(Tenant $tenant, bool $dryRun = false, bool $force = false): array
    {
        $tenantId = (string) $tenant->id;
        $result = ['tenant_id' => $tenantId, 'skipped' => null, 'fetched' => 0, 'actions' => [], 'last_uid' => null, 'dry_run' => $dryRun];

        if (! $force && ! FeatureFlag::on('outreach.inbound_poll', $tenantId)) {
            $result['skipped'] = 'flag_off';

            return $result;
        }

        $credentials = $this->mailers->credentialsFor($tenantId);
        if (! $this->reader->canRead($credentials)) {
            $result['skipped'] = 'no_imap';

            return $result;
        }

        $config = $this->settings->for($tenant);
        $lastUid = (int) ($config['mailbox']['imap_last_uid'] ?? 0);
        $folder = (string) (($config['mailbox']['imap_folder'] ?? null) ?: 'INBOX');
        $maxUid = $lastUid;
        $result['last_uid'] = $lastUid > 0 ? $lastUid : null;

        foreach ($this->reader->fetchNew($credentials, $folder, $lastUid > 0 ? $lastUid : null) as $message) {
            $result['fetched']++;
            $outcome = $this->ingestor->ingest($tenant, $message, $dryRun);
            $result['actions'][$outcome['action']] = ($result['actions'][$outcome['action']] ?? 0) + 1;
            $maxUid = max($maxUid, $message->uid);
        }

        if (! $dryRun && $maxUid > $lastUid) {
            $this->settings->update($tenant, ['mailbox' => [
                'imap_last_uid' => $maxUid,
                'imap_last_polled_at' => now()->toIso8601String(),
            ]]);
            $result['last_uid'] = $maxUid;
        }

        return $result;
    }
}
