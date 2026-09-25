<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Brain\BrainController;
use App\Services\Brain\BrainGapAnalyzer;
use App\Services\Brain\BrainManifest;
use Illuminate\Console\Command;

/**
 * `php artisan brain:gaps <tenant> [--skill=slug] [--requires=voice.tone ...]`
 * — what the brain still does not know, as a table, plus overall readiness.
 * Without --skill/--requires the manifest's readiness_order is checked.
 */
class BrainGaps extends Command
{
    protected $signature = 'brain:gaps
                            {tenant : Tenant id}
                            {--skill= : Skill slug whose card brain.requires should be checked}
                            {--requires=* : Manifest keys (voice.tone), path#section or paths}';

    protected $description = 'List missing / short / stale Knowledge-brain sections for a tenant';

    public function handle(BrainGapAnalyzer $analyzer, BrainManifest $manifest): int
    {
        $tenantId = (string) $this->argument('tenant');

        $requires = array_values(array_filter(array_map('strval', (array) $this->option('requires')), fn (string $r) => trim($r) !== ''));
        $skill = trim((string) ($this->option('skill') ?? ''));
        if ($skill !== '') {
            $fromCard = BrainController::requiresForSkill($skill);
            if ($fromCard === []) {
                $this->warn("No card.yaml with brain.requires found for skill '{$skill}'.");
            }
            $requires = array_merge($requires, $fromCard);
        }
        if ($requires === []) {
            $requires = array_map(static fn (string $path) => ['path' => $path], $manifest->readinessOrder());
        }

        try {
            $gaps = $analyzer->gaps($tenantId, $requires);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $readiness = $analyzer->readiness($tenantId);
        $this->info(sprintf('Tenant %s — brain readiness %d%%', $tenantId, $readiness['pct']));

        if ($gaps === []) {
            $this->info('No gaps: every required section is present, long enough and fresh.');

            return self::SUCCESS;
        }

        $this->table(
            ['Path', 'Section', 'Reason', 'Ask'],
            array_map(static fn (array $gap) => [
                $gap['path'],
                $gap['section'] ?? '—',
                $gap['reason'],
                $gap['question'] ?? '—',
            ], $gaps),
        );
        $this->line(count($gaps).' gap(s).');

        return self::SUCCESS;
    }
}
