<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenant;
use App\Services\Outreach\OutreachSettings;
use Illuminate\Console\Command;

/**
 * Read or change one outreach setting from the shell, for the values an
 * operator has to fill before launch (join URL, postal address, timezone,
 * whether Affonso emails API-created affiliates). The cockpit Settings page
 * covers the same ground; this is for scripted setup and for the spikes.
 */
class OutreachSettingsCommand extends Command
{
    use ResolvesTenant;

    protected $signature = 'outreach:settings
        {tenant : Tenant slug or UUID}
        {--set=* : Dotted assignments, e.g. --set=program.join_url=https://… --set=replies.mode=approve}';

    protected $description = 'Show or set per-tenant outreach settings';

    public function handle(OutreachSettings $settings): int
    {
        $tenant = $this->resolveTenant((string) $this->argument('tenant'));
        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $assignments = (array) $this->option('set');

        if ($assignments !== []) {
            $patch = [];
            foreach ($assignments as $assignment) {
                [$path, $value] = array_pad(explode('=', (string) $assignment, 2), 2, null);
                $path = trim((string) $path);
                if ($path === '' || $value === null) {
                    $this->error("Expected key=value, got '{$assignment}'.");

                    return self::FAILURE;
                }
                data_set($patch, $path, $this->cast(trim($value)));
            }

            $settings->update($tenant, $patch);
            $tenant->refresh();
            $this->info('Updated: '.implode(', ', array_map(
                fn (string $a) => trim(explode('=', $a, 2)[0]),
                array_map('strval', $assignments),
            )));
        }

        $this->line((string) json_encode($settings->for($tenant), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** "true"/"false"/"null" and numbers become their real types; everything else stays a string. */
    private function cast(string $value): mixed
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null', '' => null,
            default => is_numeric($value) ? $value + 0 : $value,
        };
    }
}
