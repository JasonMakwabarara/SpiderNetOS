<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RegisterSpidernetCommands extends Command
{
    protected $signature = 'spidernet:commands:verify';

    protected $description = 'Verify SpiderNet command registration/discovery status';

    public function handle(): int
    {
        $commands = [
            ReplayEvents::class,
            VerifyEventChain::class,
            ProjectionCheck::class,
            ReplayDetectDivergence::class,
            SpidernetPackInstall::class,
            SpidernetPackValidate::class,
            // `php artisan inference:doctor` — ModelArk/DeepSeek end-to-end
            // preflight (PR 0). The class ships in the inference-doctor change
            // set; until it lands this line reports "missing" instead of throwing.
            InferenceDoctor::class,
            VoiceRenderPreviews::class,
            // Brain / Workspaces / Skills program (PR 1+). Each class ships in its
            // own change set; class_exists() below keeps this list additive.
            BrainSync::class,
            BrainGaps::class,
            BrainExport::class,
            BrainImport::class,
            SkillsSeed::class,
            SkillsValidate::class,
            SkillsInstall::class,
            SkillsEval::class,
            AgentsRun::class,
            AgentsRuns::class,
            AgentsDoctor::class,
            BriefToday::class,
        ];

        foreach ($commands as $commandClass) {
            if (! class_exists($commandClass)) {
                $this->warn("missing:    {$commandClass}");

                continue;
            }

            $this->line("registered: {$commandClass}");
        }

        $this->info('SpiderNet command classes are discoverable by Laravel command loader.');

        return self::SUCCESS;
    }
}
