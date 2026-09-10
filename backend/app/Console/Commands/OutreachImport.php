<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Outreach\Import\ProspectImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Import an Affonso Finder shortlist export (or any CSV with a profile URL
 * column) as partner prospects. Safe to re-run: rows dedupe on the profile URL.
 */
class OutreachImport extends Command
{
    protected $signature = 'outreach:import
        {tenant : Tenant slug or UUID}
        {path : CSV file path}
        {--source=csv : Provenance label stored on the prospect (csv|finder|manual)}
        {--dry-run : Parse and count without writing}';

    protected $description = 'Import partner prospects from a CSV (Affonso Finder shortlist export)';

    public function handle(ProspectImportService $importer): int
    {
        $target = (string) $this->argument('tenant');
        $tenant = Str::isUuid($target) ? Tenant::find($target) : Tenant::where('slug', $target)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$target}' not found.");

            return self::FAILURE;
        }

        try {
            $report = $importer->importCsv((string) $tenant->id, (string) $this->argument('path'), (bool) $this->option('dry-run'), (string) $this->option('source'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($report->summary());
        foreach (array_slice($report->errors, 0, 20) as $error) {
            $this->line('  - '.$error);
        }
        if (count($report->errors) > 20) {
            $this->line('  ... '.(count($report->errors) - 20).' more');
        }

        return self::SUCCESS;
    }
}
