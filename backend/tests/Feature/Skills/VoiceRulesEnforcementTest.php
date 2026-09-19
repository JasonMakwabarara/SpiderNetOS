<?php

declare(strict_types=1);

namespace Tests\Feature\Skills;

use App\Models\BrainFile;
use App\Models\Tenant;
use App\Services\Brain\BrainSnapshot;
use App\Services\Skills\SkillOutputValidator;
use App\Services\Skills\SkillPromptBuilder;
use App\Services\Skills\SkillRegistry;
use App\Services\Skills\VoiceRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The tenant's own writing prohibitions have to be enforced on the reply, not
 * merely shown to the model.
 *
 * `SkillOutputValidator::bannedPhrases()` has always read `banned_phrases` and
 * `never_say` out of the facts array, and `SkillPromptBuilder::facts()` never
 * set either — so on every real run the validator could only see the 24
 * hardcoded defaults. A tenant could write "never offer a discount" in their
 * own brain and still have a draft offering a discount pass. These tests exist
 * so that cannot come back.
 */
class VoiceRulesEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Northbeam Books', 'slug' => 'nb-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
    }

    private function brainFile(string $path, string $content, array $frontmatter = []): void
    {
        BrainFile::create([
            'tenant_id' => $this->tenant->id, 'path' => $path, 'title' => $path,
            'content' => $content, 'frontmatter' => $frontmatter, 'source' => 'human',
            'data_class' => 'internal', 'version' => 1, 'content_hash' => hash('sha256', $content),
        ]);
    }

    private function facts(): array
    {
        $card = app(SkillRegistry::class)->get('cold-email-drafting');
        $snapshot = BrainSnapshot::capture((string) $this->tenant->id);

        return app(SkillPromptBuilder::class)->facts($card, $snapshot);
    }

    // ------------------------------------------------------------------ //
    //  The parsers
    // ------------------------------------------------------------------ //

    public function test_quoted_terms_in_do_and_dont_become_banned_phrases(): void
    {
        $voice = "## Tone\nWarm and dry.\n\n## Do and don't\nDon't say \"leverage\", \"seamless\" or \"game-changer\". Do say \"your books\".\n";

        $this->assertSame(['leverage', 'seamless', 'game-changer'], VoiceRules::bannedPhrasesFrom($voice));
    }

    public function test_unquoted_prose_is_not_turned_into_a_ban(): void
    {
        // "Don't say solutions" without quotes is prose about tone. Banning the
        // word outright would gag a company that literally sells solutions.
        $voice = "## Do and don't\nDon't say corporate buzzwords or anything that sounds like a brochure.\n";

        $this->assertSame([], VoiceRules::bannedPhrasesFrom($voice));
    }

    public function test_never_sentences_yield_the_thing_that_is_forbidden(): void
    {
        $user = "## Who I am\nThandi, founder.\n\n## Never say or offer\nNever offer a discount. Never compare us to a named competitor.\nNever promise a delivery date.\n";

        $this->assertSame(
            ['discount', 'compare us to a named competitor', 'delivery date'],
            VoiceRules::neverSayFrom($user),
        );
    }

    public function test_the_parsers_are_quiet_when_the_sections_are_absent(): void
    {
        $this->assertSame([], VoiceRules::bannedPhrasesFrom(null));
        $this->assertSame([], VoiceRules::bannedPhrasesFrom("## Tone\nWarm.\n"));
        $this->assertSame([], VoiceRules::neverSayFrom(null));
        $this->assertSame([], VoiceRules::neverSayFrom("## Who I am\nThandi.\n"));
    }

    // ------------------------------------------------------------------ //
    //  The wiring that was missing
    // ------------------------------------------------------------------ //

    public function test_the_facts_array_carries_the_tenants_own_rules(): void
    {
        $this->brainFile('brand/voice.md', "## Tone\nWarm, direct.\n\n## Do and don't\nDon't say \"synergy\".\n");
        $this->brainFile('people/user.md', "## Never say or offer\nNever offer a discount.\n");

        $facts = $this->facts();

        $this->assertSame(['synergy'], $facts['banned_phrases']);
        $this->assertSame(['discount'], $facts['never_say']);
    }

    public function test_an_empty_brain_still_produces_the_keys_the_validator_reads(): void
    {
        $facts = $this->facts();

        // Present and empty, not absent: the validator reads both keys
        // unconditionally and a missing key is a silent hole.
        $this->assertArrayHasKey('banned_phrases', $facts);
        $this->assertArrayHasKey('never_say', $facts);
        $this->assertSame([], $facts['banned_phrases']);
        $this->assertSame([], $facts['never_say']);
    }

    public function test_a_draft_that_breaks_the_tenants_own_rule_is_rejected(): void
    {
        $this->brainFile('brand/voice.md', "## Tone\nWarm.\n\n## Do and don't\nDon't say \"synergy\".\n");
        $this->brainFile('people/user.md', "## Never say or offer\nNever offer a discount.\n");

        $card = app(SkillRegistry::class)->get('cold-email-drafting');
        $facts = $this->facts();
        $validator = app(SkillOutputValidator::class);

        $offending = json_encode([
            'campaign' => 'q4', 'segment' => 'cafés', 'angle' => 'month-end',
            'steps' => array_map(fn (int $i): array => [
                'step' => $i,
                'beat' => ['problem', 'proof', 'close'][$i - 1],
                'subjects' => ['Month-end at the kitchen table', 'Closing the books by the 5th'],
                'body' => $i === 1
                    ? "We can offer a discount on the first month if that helps.\n\nWorth a look?"
                    : "A short note about month-end.\n\nWorth a look?",
                'send_day' => [0, 3, 7][$i - 1],
                'cta' => 'Worth a look?',
            ], [1, 2, 3]),
        ]);

        $result = $validator->validate($card, $offending, $facts);

        $this->assertFalse($result->ok, 'A draft offering a discount passed despite the tenant forbidding it.');
        $this->assertStringContainsString(
            'discount',
            mb_strtolower((string) json_encode($result->errors)),
            'The rejection did not name the rule that was broken.',
        );

        // The rule is doing the work, not a hardcoded default: with the
        // tenant's rules removed the same draft is no longer faulted for the
        // discount. (It is still faulted for other things — this fixture is a
        // minimal sequence, not a good one — so the assertion is about the
        // rule, not about the whole draft passing.)
        $withoutRules = array_merge($facts, ['banned_phrases' => [], 'never_say' => []]);
        $this->assertStringNotContainsString(
            'discount',
            mb_strtolower((string) json_encode($validator->validate($card, $offending, $withoutRules)->errors)),
        );
    }
}
