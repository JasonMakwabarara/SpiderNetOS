<?php

namespace App\Console\Commands;

use App\Services\FeatureFlag;
use Illuminate\Console\Command;

/**
 * Artisan command for runtime feature-flag management.
 *
 * Usage:
 *   php artisan feature:set atlas.usage_aggregates_v2.shadow on
 *   php artisan feature:set atlas.copy.empty_state fallback
 *   php artisan feature:set atlas.bandit.algo epsilon_greedy --tenant=<uuid>
 *   php artisan feature:get atlas.usage_aggregates_v2
 *   php artisan feature:get atlas.bandit.temperature
 *   php artisan feature:forget atlas.usage_aggregates_v2.shadow
 *   php artisan feature:list
 */
class FeatureFlagCommand extends Command
{
    protected $signature = 'feature {action : set|get|forget|list}
                            {name? : Flag name (e.g. atlas.usage_aggregates_v2)}
                            {value? : Value to set (on|off|fallback|<scalar>)}
                            {--tenant= : Optional tenant UUID for per-tenant override}';

    protected $description = 'Manage Atlas / SpiderNet feature flags at runtime';

    public function handle(): int
    {
        $action = $this->argument('action');
        $name = $this->argument('name');
        $value = $this->argument('value');
        $tenantId = $this->option('tenant');

        return match ($action) {
            'set' => $this->doSet($name, $value, $tenantId),
            'get' => $this->doGet($name, $tenantId),
            'forget' => $this->doForget($name, $tenantId),
            'list' => $this->doList(),
            default => $this->invalidAction($action),
        };
    }

    private function doSet(?string $name, ?string $value, ?string $tenantId): int
    {
        if (! $name || ! $value) {
            $this->error('Both <name> and <value> are required for feature:set');

            return self::FAILURE;
        }

        FeatureFlag::set($name, $value, $tenantId ?: null);

        $scope = $tenantId ? " (tenant: {$tenantId})" : ' (global)';
        $this->info("Feature flag set{$scope}: {$name} = {$value}");

        return self::SUCCESS;
    }

    private function doGet(?string $name, ?string $tenantId): int
    {
        if (! $name) {
            $this->error('<name> is required for feature:get');

            return self::FAILURE;
        }

        $val = FeatureFlag::value($name, $tenantId ?: null);
        $scope = $tenantId ? " (tenant: {$tenantId})" : '';

        $this->line("{$name}{$scope}: ".(is_bool($val) ? ($val ? 'true' : 'false') : $val));

        return self::SUCCESS;
    }

    private function doForget(?string $name, ?string $tenantId): int
    {
        if (! $name) {
            $this->error('<name> is required for feature:forget');

            return self::FAILURE;
        }

        FeatureFlag::forget($name, $tenantId ?: null);

        $scope = $tenantId ? " (tenant: {$tenantId})" : ' (global)';
        $this->info("Feature flag override removed{$scope}: {$name}");

        return self::SUCCESS;
    }

    private function doList(): int
    {
        $flags = FeatureFlag::all();

        $rows = [];
        foreach ($flags as $flag => $val) {
            $display = is_bool($val) ? ($val ? 'true' : 'false') : (string) $val;
            $rows[] = [$flag, $display];
        }

        $this->table(['Flag', 'Current Value'], $rows);

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action '{$action}'. Use: set, get, forget, list");

        return self::FAILURE;
    }
}
