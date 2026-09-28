<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Skills\SkillInstaller;
use App\Services\Skills\SkillRegistry;
use Illuminate\Console\Command;

/**
 * Enables one catalogue skill for a tenant from the CLI ("provided when
 * needed", plan D5): resolves the identity, creates/activates the agents row,
 * the workspace and the tenant_skills row. Idempotent.
 */
class SkillsInstall extends Command
{
    protected $signature = 'skills:install
        {slug : Skill card slug, e.g. cold-email-drafting}
        {--tenant= : Tenant id or slug (required)}
        {--agent= : Override the agents.slug the identity maps to}
        {--level= : Set the autonomy level after enabling (human_led|assisted|autonomous)}';

    protected $description = 'Enable a skill for a tenant: identity → agents row → workspace → tenant_skills';

    public function handle(SkillRegistry $registry, SkillInstaller $installer): int
    {
        $slug = (string) $this->argument('slug');
        $card = $registry->get($slug);
        if ($card === null) {
            $this->error("Unknown skill \"{$slug}\". Known: ".implode(', ', array_keys($registry->all())));

            return self::FAILURE;
        }

        $tenantRef = (string) $this->option('tenant');
        if ($tenantRef === '') {
            $this->error('--tenant=<id|slug> is required.');

            return self::FAILURE;
        }
        $tenant = Tenant::find($tenantRef) ?? Tenant::where('slug', $tenantRef)->first();
        if ($tenant === null) {
            $this->error("Tenant \"{$tenantRef}\" not found.");

            return self::FAILURE;
        }

        try {
            $row = $installer->installForTenant((string) $tenant->id, $slug, $this->option('agent') ?: null, 'cli');
            if ($level = $this->option('level')) {
                $row = $installer->setAutonomy((string) $tenant->id, $slug, (string) $level);
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $identity = $registry->identityFor($card);
        $this->info("{$card->displayName} enabled for {$tenant->slug}");
        $this->table(['field', 'value'], [
            ['skill', $slug],
            ['identity', $identity['key'].' ('.($identity['display_name'] ?? '').') → '.$card->coreAgent],
            ['agents.slug', $identity['agents_slug'] ?? '(service, no agents row)'],
            ['agent_id', $row->agent_id ?? '-'],
            ['workspace_id', $row->workspace_id ?? '-'],
            ['autonomy_level', $row->autonomy_level],
            ['enabled', $row->enabled ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
