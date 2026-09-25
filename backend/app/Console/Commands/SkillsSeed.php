<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Skills\SkillCatalogue;
use App\Services\Skills\SkillRegistry;
use Illuminate\Console\Command;

/**
 * Projects packages/skills/<slug>/ into the `skills` + `skill_relations`
 * tables (plan D5). Idempotent by card_hash; runs the validator first unless
 * --force so a broken card never lands in the catalogue.
 */
class SkillsSeed extends Command
{
    protected $signature = 'skills:seed
        {--force : Seed even when skills:validate reports errors}
        {--root= : Override the skills root (defaults to config agents.skills_root)}';

    protected $description = 'Seed the skills catalogue (skills + skill_relations) from packages/skills/**';

    public function handle(): int
    {
        $registry = new SkillRegistry($this->option('root') ?: null);
        $catalogue = new SkillCatalogue($registry);

        $problems = array_filter($registry->validateAll(), fn (array $errors) => $errors !== []);
        if ($problems !== [] && ! $this->option('force')) {
            foreach ($problems as $slug => $errors) {
                $this->error("{$slug}:");
                foreach ($errors as $error) {
                    $this->line("  - {$error}");
                }
            }
            $this->error('Fix the cards above (or pass --force) before seeding.');

            return self::FAILURE;
        }

        $summary = $catalogue->seed();

        $this->info(sprintf(
            'skills: %d created, %d updated, %d unchanged; %d relation rows written',
            $summary['created'],
            $summary['updated'],
            $summary['unchanged'],
            $summary['relations'],
        ));
        $this->line('  '.implode(', ', $summary['slugs']));

        return self::SUCCESS;
    }
}
