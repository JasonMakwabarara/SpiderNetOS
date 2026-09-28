<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Brain\BrainSyncService;
use Illuminate\Console\Command;

/**
 * `php artisan brain:sync --tenant=<id>` / `--all` — project the business
 * context tables into the Knowledge brain (plan D2). Idempotent: a file
 * whose projected content is unchanged keeps its version.
 */
class BrainSync extends Command
{
    protected $signature = 'brain:sync
                            {--tenant= : Sync one tenant id}
                            {--all : Sync every active tenant}';

    protected $description = 'Project business context (profile, alignment, interview, scripts, processes, outreach, user) into the Knowledge brain';

    public function handle(BrainSyncService $sync): int
    {
        $tenantIds = [];
        if ($this->option('tenant')) {
            $tenantIds = [(string) $this->option('tenant')];
        } elseif ($this->option('all')) {
            $tenantIds = Tenant::where('status', 'active')->orderBy('created_at')->pluck('id')->map(fn ($id) => (string) $id)->all();
        } else {
            $this->error('Pass --tenant=<id> or --all.');

            return self::INVALID;
        }

        if ($tenantIds === []) {
            $this->warn('No tenants to sync.');

            return self::SUCCESS;
        }

        $failures = 0;
        foreach ($tenantIds as $tenantId) {
            try {
                $written = $sync->syncAll($tenantId);
                $this->info(sprintf('Tenant %s: %d file(s) written.', $tenantId, count($written)));
                foreach ($written as $path) {
                    $this->line('  '.$path);
                }
            } catch (\Throwable $e) {
                $failures++;
                $this->error("Tenant {$tenantId}: ".$e->getMessage());
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
