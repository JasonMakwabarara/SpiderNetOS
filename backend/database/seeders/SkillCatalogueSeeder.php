<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Skills\SkillCatalogue;
use App\Services\Skills\SkillRegistry;
use Illuminate\Database\Seeder;

/**
 * Seeds the global skills catalogue (skills + skill_relations) from
 * packages/skills/** — the same projection `php artisan skills:seed` runs.
 * Idempotent by card_hash, so it is safe in DatabaseSeeder and in deploys.
 */
class SkillCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $summary = (new SkillCatalogue(new SkillRegistry))->seed();

        if ($this->command) {
            $this->command->info(sprintf(
                'skills catalogue: %d created, %d updated, %d unchanged, %d relations',
                $summary['created'],
                $summary['updated'],
                $summary['unchanged'],
                $summary['relations'],
            ));
        }
    }
}
