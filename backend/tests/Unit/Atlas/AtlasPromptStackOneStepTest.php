<?php

declare(strict_types=1);

namespace Tests\Unit\Atlas;

use App\Services\AtlasPromptStack;
use PHPUnit\Framework\TestCase;

/** The "one step further" standing rule renders only when $oneStep is passed (plan D8). */
class AtlasPromptStackOneStepTest extends TestCase
{
    public function test_plain_prompt_has_no_rule_and_no_blocks(): void
    {
        $prompt = (new AtlasPromptStack)->systemPrompt();

        $this->assertStringContainsString('You are Atlas', $prompt);
        $this->assertStringNotContainsString('<NEXT_STEP>', $prompt);
        $this->assertStringNotContainsString('<ONE_MORE_QUESTION>', $prompt);
        $this->assertStringNotContainsString('Standing rule', $prompt);
        $this->assertStringNotContainsString('<BRAIN>', $prompt);
        $this->assertSame($prompt, (new AtlasPromptStack)->systemPrompt(null, null));
    }

    public function test_one_step_renders_blocks_and_the_standing_rule_after_the_base_prompt(): void
    {
        $prompt = (new AtlasPromptStack)->systemPrompt(null, [
            'question' => 'How is the offer priced?',
            'next_step' => ['label' => 'Draft the follow-up cadence', 'does' => 'Three follow-ups over 9 days', 'skill' => 'follow-up-drafter'],
        ]);

        $this->assertStringContainsString("<NEXT_STEP>\nDraft the follow-up cadence — Three follow-ups over 9 days\n</NEXT_STEP>", $prompt);
        $this->assertStringContainsString("<ONE_MORE_QUESTION>\nHow is the offer priced? (or say skip)\n</ONE_MORE_QUESTION>", $prompt);
        $this->assertStringContainsString(AtlasPromptStack::ONE_STEP_RULE, $prompt);
        $this->assertStringContainsString('NEXT:', $prompt);
        $this->assertStringContainsString('ASK:', $prompt);
        $this->assertStringContainsString('never change a number, a fact or a decision', $prompt);
        $this->assertGreaterThan(strpos($prompt, 'You are Atlas'), strpos($prompt, '<NEXT_STEP>'), 'blocks come after the identity prompt');
    }

    public function test_empty_next_step_and_question_fall_back_to_asking_the_user_for_a_step(): void
    {
        $prompt = (new AtlasPromptStack)->systemPrompt(null, ['question' => null, 'next_step' => null]);

        $this->assertStringContainsString("<NEXT_STEP>\n\n</NEXT_STEP>", $prompt);
        $this->assertStringContainsString(AtlasPromptStack::USER_STEP_QUESTION, $prompt);
        $this->assertStringContainsString('(or say skip)', $prompt);
    }

    public function test_brain_block_renders_only_when_given(): void
    {
        $stack = new AtlasPromptStack;

        $this->assertStringContainsString("<BRAIN>\n## Offer\nPriced per seat.\n</BRAIN>", $stack->systemPrompt(['block' => "## Offer\nPriced per seat."]));
        $this->assertStringContainsString("<BRAIN>\nname: Acme\n</BRAIN>", $stack->systemPrompt(['name' => 'Acme']));
        $this->assertStringNotContainsString('<BRAIN>', $stack->systemPrompt([]));

        $both = $stack->systemPrompt(['block' => 'x'], ['question' => 'Q?']);
        $this->assertLessThan(strpos($both, '<NEXT_STEP>'), strpos($both, '<BRAIN>'), 'BRAIN precedes the one-step blocks');
    }
}
