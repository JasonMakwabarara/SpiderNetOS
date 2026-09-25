<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Reports\WeeklyLetterController;
use App\Models\Tenant;
use App\Services\Reports\MondayLetterComposer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * php artisan letter:monday {tenant} [--json] [--week=YYYY-Www]
 *
 * Composes the Monday letter (plan D8 #11) and the C-Suite newsletter inside
 * it (D8 #15) for one tenant, files both under reports/weekly/ in the
 * Knowledge brain, and prints the result. Composing is deterministic, so
 * running it twice for the same week rewrites the same letter — it does not
 * send anything.
 */
class LetterMonday extends Command
{
    protected $signature = 'letter:monday {tenant : Tenant id or slug} {--json : Print the whole letter as JSON} {--week= : Compose for this ISO week (e.g. 2026-W38)}';

    protected $description = 'Compose the Monday letter and the C-Suite newsletter for a tenant';

    public function handle(MondayLetterComposer $composer): int
    {
        $ref = (string) $this->argument('tenant');
        $tenant = Str::isUuid($ref) ? Tenant::find($ref) : Tenant::where('slug', $ref)->first();
        if ($tenant === null) {
            $this->error("Tenant [{$ref}] not found.");

            return self::FAILURE;
        }

        $for = null;
        if ($this->option('week')) {
            $for = WeeklyLetterController::weekStart((string) $this->option('week'));
            if ($for === null) {
                $this->error('Invalid --week; use an ISO week like 2026-W38.');

                return self::INVALID;
            }
            $for = Carbon::instance($for->toDateTime());
        }

        $letter = $composer->compose((string) $tenant->id, $for);

        if ($this->option('json')) {
            $this->line((string) json_encode($letter, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line($letter['markdown']);
        $this->newLine();
        $this->info('Filed at '.($letter['paths']['letter'] ?? 'nowhere — the brain tables are missing')
            .' and '.($letter['paths']['csuite'] ?? 'nowhere'));

        return self::SUCCESS;
    }
}
