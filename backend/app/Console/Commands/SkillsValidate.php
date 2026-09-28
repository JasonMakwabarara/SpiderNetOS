<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Skills\SkillRegistry;
use Illuminate\Console\Command;

/**
 * Validates every packages/skills/<slug>/ folder against the card schema and
 * the cross-file rules (identities, brain manifest keys/paths, tools,
 * hand-off slugs, one-step-further paths, SKILL.md sections, task placeholders,
 * evals). Exit 1 on any error — CI runs this on packages/skills/**.
 */
class SkillsValidate extends Command
{
    protected $signature = 'skills:validate
        {slug? : Validate a single card}
        {--root= : Override the skills root (defaults to config agents.skills_root)}
        {--json : Print machine-readable results}';

    protected $description = 'Validate skill cards under packages/skills/** (schema + cross-file rules)';

    public function handle(): int
    {
        $registry = new SkillRegistry($this->option('root') ?: null);
        $results = $registry->validateAll();

        $only = $this->argument('slug');
        if ($only !== null) {
            if (! array_key_exists($only, $results)) {
                $this->error("Unknown skill \"{$only}\" under {$registry->root()}");

                return self::FAILURE;
            }
            $results = [$only => $results[$only]];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['root' => $registry->root(), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return array_filter($results) === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($results === []) {
            $this->warn("No skill cards found under {$registry->root()}");

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($results as $slug => $errors) {
            if ($errors === []) {
                $this->line("<info>ok</info>      {$slug}");

                continue;
            }
            $failed++;
            $this->line("<error>invalid</error> {$slug}");
            foreach ($errors as $error) {
                $this->line("          - {$error}");
            }
        }

        $total = count($results);
        if ($failed > 0) {
            $this->error("{$failed} of {$total} skill cards have errors.");

            return self::FAILURE;
        }

        $this->info("All {$total} skill cards are valid.");

        return self::SUCCESS;
    }
}
