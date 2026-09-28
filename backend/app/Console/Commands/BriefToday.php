<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Founder\FounderBriefService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * php artisan brief:today {tenant} [--json] [--date=YYYY-MM-DD]
 *
 * Prints the deterministic Needs-You Today brief (plan D8 #3) for a tenant
 * (id or slug) and files it under reports/daily/ in the Knowledge brain.
 */
class BriefToday extends Command
{
    protected $signature = 'brief:today {tenant : Tenant id or slug} {--json : Print the raw brief as JSON} {--date= : Compose the brief for this date (Y-m-d)}';

    protected $description = 'Print the Needs-You Today brief for a tenant (max 7 ranked items, overnight, one more question)';

    public function handle(FounderBriefService $briefs): int
    {
        $ref = (string) $this->argument('tenant');
        $tenant = Str::isUuid($ref) ? Tenant::find($ref) : Tenant::where('slug', $ref)->first();
        if ($tenant === null) {
            $this->error("Tenant [{$ref}] not found.");

            return self::FAILURE;
        }

        $for = null;
        if ($this->option('date')) {
            try {
                $for = new \DateTimeImmutable((string) $this->option('date'));
            } catch (\Throwable) {
                $this->error('Invalid --date; use Y-m-d.');

                return self::INVALID;
            }
        }

        $brief = $briefs->compose((string) $tenant->id, $for);

        if ($this->option('json')) {
            $this->line((string) json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Needs-You Today — {$brief['date']} ({$tenant->name}, {$brief['timezone']})");
        $this->newLine();

        if ($brief['items'] === []) {
            $this->line('Nothing is waiting on you.');
        } else {
            $this->table(
                ['#', 'Kind', 'Item', 'Detail', 'Age', 'Money', 'Action'],
                array_map(fn (array $i): array => [
                    $i['rank'],
                    $i['kind'],
                    Str::limit($i['title'], 60),
                    Str::limit($i['detail'], 70),
                    $i['age'] ?? '',
                    $i['money'] !== null ? FounderBriefService::money((float) $i['money']) : '',
                    $i['action']['label'].' → '.$i['action']['path'],
                ], $brief['items']),
            );
        }

        $this->newLine();
        $this->line('<comment>Overnight</comment>');
        if ($brief['overnight'] === []) {
            $this->line('  Nothing ran overnight.');
        } else {
            foreach ($brief['overnight'] as $o) {
                $this->line('  - '.$o['title'].' → '.$o['path']);
            }
        }

        if (! empty($brief['one_more_question']['question'])) {
            $this->newLine();
            $this->line('<comment>One more question</comment>');
            $this->line('  '.$brief['one_more_question']['question']);
        }

        $this->newLine();
        $this->line($brief['path'] ? "Filed at {$brief['path']}" : 'Not filed (brain tables absent).');

        return self::SUCCESS;
    }
}
