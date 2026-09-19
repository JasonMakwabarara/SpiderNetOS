<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BusinessProcess;
use App\Models\BusinessSystem;
use App\Models\Sop;
use App\Models\Tenant;
use App\Services\EventStore;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Seed business systems, processes, and published v1 SOPs from the
 * business-systemization template library
 * (packages/feature-packs/business-systemization/templates/*.yaml).
 *
 * Idempotent: existing (tenant, function, name) systems and existing
 * (system, name) processes are skipped, and processes that already carry
 * any SOP version keep what they have.
 */
class SeedSystemTemplates extends Command
{
    protected $signature = 'systems:seed-templates {--tenant= : Seed a single tenant id (defaults to every active tenant)} {--function= : Only seed templates for this business function}';

    protected $description = 'Seed business systems, processes, and published SOPs from the systemization template library';

    public function handle(EventStore $eventStore): int
    {
        $function = $this->option('function') ?: null;
        $templates = self::loadTemplates($function);

        if ($templates === []) {
            $this->warn($function
                ? "No system templates found for function \"{$function}\"."
                : 'No system templates found.');

            return self::FAILURE;
        }

        $tenants = $this->resolveTenants();

        if ($tenants === null) {
            return self::FAILURE;
        }

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants to seed.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $summary = $this->seedTenant((string) $tenant->id, $templates, $eventStore);

            $this->info(sprintf(
                '%s: +%d systems, +%d processes, +%d sops (functions: %s)',
                $tenant->slug ?? $tenant->id,
                $summary['systems_created'],
                $summary['processes_created'],
                $summary['sops_created'],
                implode(', ', $summary['functions']),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * True when at least one seedable template file exists — the guard the
     * systemization bootstrap uses before invoking this command.
     */
    public static function templatesAvailable(): bool
    {
        return self::loadTemplates() !== [];
    }

    /**
     * Parse every template YAML (optionally filtered by function) into
     * [['function' => ..., 'systems' => [...], 'file' => ...], ...].
     * Malformed files are skipped rather than fatal.
     */
    public static function loadTemplates(?string $function = null): array
    {
        $dir = self::templatesPath();

        if (! is_dir($dir)) {
            return [];
        }

        $templates = [];

        foreach (glob($dir.'/*.yaml') ?: [] as $file) {
            try {
                $parsed = Yaml::parseFile($file);
            } catch (\Throwable) {
                continue;
            }

            if (! is_array($parsed)
                || ! is_string($parsed['function'] ?? null)
                || ! is_array($parsed['systems'] ?? null)
                || $parsed['systems'] === []) {
                continue;
            }

            if ($function !== null && $parsed['function'] !== $function) {
                continue;
            }

            $templates[] = [
                'function' => $parsed['function'],
                'systems' => $parsed['systems'],
                'file' => basename($file),
            ];
        }

        return $templates;
    }

    private static function templatesPath(): string
    {
        $root = rtrim((string) env('FEATURE_PACKS_ROOT', dirname(base_path()).'/packages/feature-packs'), '/');

        return $root.'/business-systemization/templates';
    }

    private function resolveTenants(): ?Collection
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenant = Tenant::query()->find($tenantId);

            if (! $tenant) {
                $this->error("Tenant {$tenantId} not found.");

                return null;
            }

            return collect([$tenant]);
        }

        return Tenant::query()->where('status', 'active')->get()->collect();
    }

    /**
     * @param  array<int, array{function: string, systems: array, file: string}>  $templates
     * @return array{functions: array<int, string>, systems_created: int, processes_created: int, sops_created: int}
     */
    private function seedTenant(string $tenantId, array $templates, EventStore $eventStore): array
    {
        return DB::transaction(function () use ($tenantId, $templates, $eventStore) {
            $summary = [
                'functions' => [],
                'systems_created' => 0,
                'processes_created' => 0,
                'sops_created' => 0,
            ];

            foreach ($templates as $template) {
                $summary['functions'][] = $template['function'];

                foreach ($template['systems'] as $systemDef) {
                    if (! is_array($systemDef) || ! is_string($systemDef['name'] ?? null)) {
                        continue;
                    }

                    $system = BusinessSystem::query()->firstOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'function' => $template['function'],
                            'name' => $systemDef['name'],
                        ],
                        [
                            'goal' => $systemDef['goal'] ?? null,
                            'status' => 'active',
                        ],
                    );

                    if ($system->wasRecentlyCreated) {
                        $summary['systems_created']++;
                    }

                    foreach (array_values($systemDef['processes'] ?? []) as $position => $processDef) {
                        if (! is_array($processDef) || ! is_string($processDef['name'] ?? null)) {
                            continue;
                        }

                        $process = BusinessProcess::query()->firstOrCreate(
                            [
                                'tenant_id' => $tenantId,
                                'system_id' => (string) $system->id,
                                'name' => $processDef['name'],
                            ],
                            [
                                'goal' => $processDef['goal'] ?? null,
                                'effort_size' => (int) ($processDef['effort_size'] ?? 3),
                                'position' => $position,
                                'owner_type' => 'founder',
                                'status' => 'founder_owned',
                            ],
                        );

                        if ($process->wasRecentlyCreated) {
                            $summary['processes_created']++;
                        }

                        $sopDef = $processDef['sop'] ?? null;

                        // Keep existing SOP versions untouched — only processes
                        // with no SOP at all receive the published template v1.
                        if (is_array($sopDef)
                            && is_string($sopDef['title'] ?? null)
                            && ! Sop::query()->where('process_id', (string) $process->id)->exists()) {
                            Sop::create([
                                'tenant_id' => $tenantId,
                                'process_id' => (string) $process->id,
                                'version' => 1,
                                'title' => $sopDef['title'],
                                'purpose' => $sopDef['purpose'] ?? null,
                                'trigger' => $sopDef['trigger'] ?? null,
                                'tools' => array_values($sopDef['tools'] ?? []),
                                'steps' => array_values($sopDef['steps'] ?? []),
                                'quality_criteria' => array_values($sopDef['quality_criteria'] ?? []),
                                'status' => 'published',
                            ]);

                            $summary['sops_created']++;
                        }
                    }
                }
            }

            if ($summary['systems_created'] + $summary['processes_created'] + $summary['sops_created'] > 0) {
                // Short-form append: (tenantId, eventType, payload).
                $eventStore->append($tenantId, 'systemization.templates_seeded', [
                    'functions' => $summary['functions'],
                    'systems_created' => $summary['systems_created'],
                    'processes_created' => $summary['processes_created'],
                    'sops_created' => $summary['sops_created'],
                    'source' => 'template_seeder',
                ]);
            }

            return $summary;
        });
    }
}
