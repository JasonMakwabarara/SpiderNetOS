<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenant;
use App\Services\Outreach\Ops\OutreachDigest;
use Illuminate\Console\Command;

/** Manual / dry-run entry point for the daily outreach digest and its auto-pause. */
class OutreachDigestRun extends Command
{
    use ResolvesTenant;

    protected $signature = 'outreach:digest
        {tenant? : Tenant slug or UUID (default: every tenant with outreach configured)}
        {--dry-run : Print the digest without notifying anyone or pausing}
        {--force : Run even when the outreach.digest flag is off}';

    protected $description = 'Send the partner-outreach digest and auto-pause sending if the bounce rate is too high';

    public function handle(OutreachDigest $digests): int
    {
        $tenants = $this->resolveTenants((string) $this->argument('tenant'));

        if ($tenants->isEmpty()) {
            $this->warn('No tenant has outreach configured.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $result = $digests->run($tenant, (bool) $this->option('dry-run'), (bool) $this->option('force'));

            $this->newLine();
            $this->line("<options=bold>{$tenant->name}</> ({$tenant->slug})");

            if ($result['skipped'] !== null) {
                $this->warn('skipped: '.$result['skipped'].' (use --force to run anyway)');

                continue;
            }

            foreach ((array) $result['lines'] as $line) {
                $this->line('  '.$line);
            }

            if ($result['paused']) {
                $this->error('Sending PAUSED: '.$result['pause_reason']);
                $this->line('  Resume with: php artisan outreach:enable send --tenant='.$tenant->slug);
            }
            if ($result['dry_run']) {
                $this->comment('  dry run: nobody was notified and nothing was paused');
            }
        }

        return self::SUCCESS;
    }
}
