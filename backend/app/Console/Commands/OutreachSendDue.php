<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Outreach\OutreachSender;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Manual / dry-run entry point for the outreach tick (the scheduler runs
 * ProcessOutreachStepsJob with the same service every minute).
 */
class OutreachSendDue extends Command
{
    protected $signature = 'outreach:send-due
        {tenant? : Tenant slug or UUID (default: every tenant with outreach configured)}
        {--dry-run : Report what would be drafted/retired/sent without writing}
        {--force : Run even when the outreach.sending flag is off}';

    protected $description = 'Run one partner-outreach tick: DM drafts, retirements and due email steps';

    public function handle(OutreachSender $sender): int
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
            $tenants = $sender->tenants();
        }

        if ($tenants->isEmpty()) {
            $this->warn('No tenant has outreach configured (run outreach:tenant first).');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            $r = $sender->runForTenant($tenant, (bool) $this->option('dry-run'), (bool) $this->option('force'));
            $rows[] = [$tenant->slug, $r['skipped'] ?? '-', $r['drafted'], $r['retired'], $r['due'], $r['sent'], $r['failed']];
        }

        $this->table(['tenant', 'skipped', 'drafted', 'retired', 'due', 'sent', 'failed'], $rows);
        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
