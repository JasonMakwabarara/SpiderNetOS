<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Outreach\Inbound\InboxPoller;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Manual / dry-run mailbox poll (the scheduler runs PollPartnerMailboxJob). */
class OutreachPollInbox extends Command
{
    protected $signature = 'outreach:poll-inbox
        {tenant? : Tenant slug or UUID (default: every tenant with outreach configured)}
        {--dry-run : Classify and match without storing anything or moving the UID cursor}
        {--force : Run even when the outreach.inbound_poll flag is off}';

    protected $description = 'Poll partner mailboxes for replies, bounces and unsubscribes';

    public function handle(InboxPoller $poller): int
    {
        $target = (string) $this->argument('tenant');
        if ($target !== '') {
            $tenant = Str::isUuid($target) ? Tenant::find($target) : Tenant::where('slug', $target)->first();
            if ($tenant === null) {
                $this->error("Tenant '{$target}' not found.");

                return self::FAILURE;
            }
            $tenants = collect([$tenant]);
        } else {
            $tenants = $poller->tenants();
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            $r = $poller->pollTenant($tenant, (bool) $this->option('dry-run'), (bool) $this->option('force'));
            $actions = collect($r['actions'])->map(fn ($n, $k) => "{$k}={$n}")->implode(' ');
            $rows[] = [$tenant->slug, $r['skipped'] ?? '-', $r['fetched'], $actions !== '' ? $actions : '-', $r['last_uid'] ?? '-'];
        }

        $this->table(['tenant', 'skipped', 'fetched', 'actions', 'last_uid'], $rows);
        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing was stored.');
        }

        return self::SUCCESS;
    }
}
