<?php

declare(strict_types=1);

namespace Tests\Unit\Skills;

use App\Services\Skills\SkillCard;
use App\Services\Skills\SkillRegistry;
use Tests\TestCase;

/**
 * The eight first-release cards load, validate against the schema and the
 * cross-file rules, and every reference they make (brain keys, tools,
 * hand-offs, next steps) resolves to something the OS knows about.
 */
class SkillRegistryTest extends TestCase
{
    public const FIRST_EIGHT = [
        'brand-voice-keeper',
        'cold-email-drafting',
        'follow-up-drafting',
        'icp-definition',
        'inbox-triage-reply-classifier',
        'linkedin-outreach-specialist',
        'meeting-booking',
        'prospect-research-analysis',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SkillRegistry::flush();
    }

    private function registry(): SkillRegistry
    {
        return new SkillRegistry;
    }

    public function test_all_eight_cards_load(): void
    {
        $cards = $this->registry()->all();

        $this->assertSame(self::FIRST_EIGHT, array_keys($cards));
        foreach ($cards as $slug => $card) {
            $this->assertInstanceOf(SkillCard::class, $card);
            $this->assertSame($slug, $card->id);
            $this->assertNotSame('', $card->promptBody(), "{$slug} SKILL.md body is empty");
            $this->assertStringNotContainsString("---\nname:", $card->promptBody(), "{$slug} promptBody() still carries frontmatter");
        }
    }

    public function test_every_card_validates(): void
    {
        $results = $this->registry()->validateAll();

        $failures = array_filter($results, fn (array $errors) => $errors !== []);
        $this->assertSame([], $failures, "Invalid cards:\n".json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertCount(8, $results);
    }

    public function test_every_brain_requires_key_resolves_against_the_manifest(): void
    {
        $registry = $this->registry();
        $checked = 0;
        foreach ($registry->all() as $slug => $card) {
            foreach ((array) ($card->card['brain']['requires'] ?? []) as $entry) {
                $this->assertArrayHasKey('key', $entry, "{$slug}: brain.requires entries use manifest keys");
                $resolved = $registry->resolveBrainKey((string) $entry['key']);
                $this->assertNotNull($resolved, "{$slug}: key {$entry['key']} does not resolve");
                [$path, $section] = SkillCard::splitPath($resolved);
                $this->assertSame($entry['path'], $path, "{$slug}: key {$entry['key']} resolves to {$path}");
                $this->assertNotNull($registry->manifestEntryFor($path), "{$slug}: {$path} is not in the manifest");
                $checked++;
            }
            // The DTO carries the resolved section too.
            foreach ($card->brain['requires'] as $entry) {
                $this->assertNotSame([], $entry['sections'], "{$slug}: {$entry['path']} has no sections after resolution");
            }
        }
        $this->assertGreaterThan(8, $checked);
    }

    public function test_every_tool_hand_off_and_next_step_references_a_known_or_planned_skill(): void
    {
        $registry = $this->registry();
        $planned = [];
        foreach ($registry->all() as $slug => $card) {
            foreach ($card->tools as $tool) {
                $this->assertTrue($registry->isKnownTool($tool), "{$slug}: unknown tool {$tool}");
            }
            foreach ($card->handsOffTo() as $handOff) {
                if (($handOff['type'] ?? '') !== 'skill') {
                    continue;
                }
                $target = (string) $handOff['slug'];
                $this->assertTrue($registry->isKnownOrPlanned($target), "{$slug}: hands off to unknown skill {$target}");
                if ($registry->isPlannedSkill($target)) {
                    $planned[] = $target;
                }
            }
            foreach ($card->nextStepTemplates() as $step) {
                $this->assertTrue($registry->isKnownOrPlanned((string) $step['skill']), "{$slug}: next step names unknown skill {$step['skill']}");
            }
        }
        // Richard's runner is in the roster but ships in PR 7 → planned, never "unknown".
        $this->assertContains('linkedin-campaign-runner', $planned);
        $this->assertTrue($registry->isPlannedSkill('linkedin-campaign-runner'));
        $this->assertFalse($registry->isKnownOrPlanned('no-such-skill'));
    }

    public function test_for_trigger_returns_the_event_driven_cards(): void
    {
        $cards = $this->registry()->forTrigger('conversation.message.received');

        $this->assertArrayHasKey('inbox-triage-reply-classifier', $cards);
        $this->assertArrayHasKey('meeting-booking', $cards);
        $this->assertArrayNotHasKey('cold-email-drafting', $cards);
        $this->assertSame([], $this->registry()->forTrigger('no.such.event'));
    }

    public function test_match_intent_uses_keywords_and_display_names(): void
    {
        $registry = $this->registry();

        $this->assertSame('cold-email-drafting', $registry->matchIntent('Can you write a cold email sequence for Cape Town cafés?')?->id);
        $this->assertSame('inbox-triage-reply-classifier', $registry->matchIntent('who replied in my inbox this morning')?->id);
        $this->assertSame('meeting-booking', $registry->matchIntent('propose a time to book a call with Lerato')?->id);
        $this->assertSame('brand-voice-keeper', $registry->matchIntent('Let us fix our brand voice')?->id);
        $this->assertSame('linkedin-outreach-specialist', $registry->matchIntent('LinkedIn Outreach Specialist please')?->id);
        $this->assertNull($registry->matchIntent('what is the weather like'));
        $this->assertNull($registry->matchIntent(''));
    }

    public function test_identity_and_core_agent_resolution(): void
    {
        $registry = $this->registry();
        $card = $registry->get('cold-email-drafting');
        $this->assertNotNull($card);

        $identity = $registry->identityFor($card);
        $this->assertSame('growth', $identity['key']);
        $this->assertSame('sales_crm_growth', $identity['agents_slug']);
        $this->assertSame('nexus', $identity['reports_to']);
        $this->assertSame('nexus', $registry->coreAgentFor($card));

        $triage = $registry->get('inbox-triage-reply-classifier');
        $this->assertSame('crm', $registry->identityFor($triage)['key']);
        $this->assertSame('sentinel', $registry->coreAgentFor($triage), 'the monitor half is Sentinel');

        $this->assertSame('prism', $registry->identityFor($registry->get('prospect-research-analysis'))['agents_slug']);
        $this->assertNull($registry->get('nope'));
    }

    public function test_card_dto_exposes_the_contract(): void
    {
        $card = $this->registry()->get('cold-email-drafting');

        $this->assertSame('Cold Email Drafting', $card->displayName);
        $this->assertSame('1.0.0', $card->version);
        $this->assertSame('sales', $card->pillar);
        $this->assertSame('single_shot', $card->mode);
        $this->assertSame('sales-crm', $card->packId);
        $this->assertSame(['default' => 'human_led', 'ladder' => ['human_led', 'assisted', 'autonomous']], $card->autonomy);
        $this->assertSame(['drafts.save_sequence', 'drafts.submit_for_review'], $card->postActions);
        $this->assertSame('draft_sequence', $card->primaryOutputKind());
        $this->assertSame(3, $card->outputSchema('draft_sequence')['properties']['steps']['minItems']);
        $this->assertNull($card->outputSchema('report'));
        $this->assertContains('guaranteed', $card->bannedPhrases());
        $this->assertSame('offer/offer.md', $card->brain['requires'][2]['path']);
        $this->assertContains('Products and services', $card->brain['requires'][2]['sections']);
        $this->assertSame(['offer/approved-sequences.md'], $card->brain['writes']);
        $this->assertCount(2, $card->oneStepFurther['steps']);

        $prompt = $card->taskPrompt(['campaign' => 'cafes-q4', 'segment' => 'Cape Town cafés']);
        $this->assertStringContainsString('Campaign: cafes-q4', $prompt);
        $this->assertStringContainsString('Segment: Cape Town cafés', $prompt);
        $this->assertStringContainsString('Steps: 3', $prompt, 'const/default inputs are rendered');
        $this->assertStringContainsString('Research report: (not given)', $prompt);
        $this->assertStringContainsString('{{first_line}}', $prompt, 'non-input placeholders stay for the model');
        $this->assertStringNotContainsString('{{inputs.', $prompt);
    }
}
