<?php

declare(strict_types=1);

namespace Tests\Unit\Skills;

use App\Models\Skill;
use App\Services\Skills\SkillRegistry;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The roster's three declarations have to agree with each other.
 *
 *   packages/skills/identities.yaml        identities -> reports_to, skills[]
 *   packages/core-agents/<slug>/CHARACTER.md  owns[] -> identity, skills[]
 *   packages/skills/<slug>/card.yaml       runs_on, core_agent
 *
 * Nothing checked that they did, and they had already drifted: Forge's sheet
 * declared it owned an identity called `runbook_automator` that does not exist
 * in identities.yaml — a phantom, unguarded, referenced by nothing that would
 * ever fail.
 *
 * This is the one thing msitarzewski/agency-agents does better than
 * SpiderNetOS: `divisions.json` names its own cross-check script in a `_note`,
 * and CI fails the build when any copy of the list disagrees. A repo of
 * stateless prose enforced its metadata harder than this one did.
 */
class RosterClosureTest extends TestCase
{
    private function packagesRoot(): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'packages';
    }

    /** @return array<string, mixed> */
    private function identities(): array
    {
        $path = $this->packagesRoot().DIRECTORY_SEPARATOR.'skills'.DIRECTORY_SEPARATOR.'identities.yaml';
        $this->assertFileExists($path);

        return (array) Yaml::parseFile($path);
    }

    /** @return array<string, array<string, mixed>> keyed by character slug */
    private function characters(): array
    {
        $root = $this->packagesRoot().DIRECTORY_SEPARATOR.'core-agents';
        $sheets = [];

        foreach ((array) glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'CHARACTER.md') as $path) {
            $raw = (string) file_get_contents((string) $path);
            $parts = preg_split('/^---\s*$/m', ltrim($raw), 3);
            $this->assertIsArray($parts, "Unparseable character sheet: {$path}");
            $this->assertGreaterThanOrEqual(3, count($parts), "No frontmatter in {$path}");

            $front = (array) Yaml::parse($parts[1]);
            $sheets[(string) ($front['slug'] ?? basename(dirname((string) $path)))] = $front;
        }

        return $sheets;
    }

    public function test_every_identity_a_character_claims_to_own_actually_exists(): void
    {
        $identityKeys = array_keys((array) ($this->identities()['identities'] ?? []));
        $this->assertNotEmpty($identityKeys);

        foreach ($this->characters() as $slug => $sheet) {
            foreach ((array) ($sheet['owns'] ?? []) as $entry) {
                if (! is_array($entry) || ! isset($entry['identity'])) {
                    continue;
                }
                $this->assertContains(
                    (string) $entry['identity'],
                    $identityKeys,
                    "{$slug}/CHARACTER.md claims identity \"{$entry['identity']}\", which is not in identities.yaml.",
                );
            }
        }
    }

    public function test_a_character_only_claims_skills_that_identity_actually_runs(): void
    {
        $identities = (array) ($this->identities()['identities'] ?? []);

        foreach ($this->characters() as $slug => $sheet) {
            foreach ((array) ($sheet['owns'] ?? []) as $entry) {
                $identity = is_array($entry) ? (string) ($entry['identity'] ?? '') : '';
                if ($identity === '' || ! isset($identities[$identity])) {
                    continue;
                }

                $declared = array_map('strval', (array) ($identities[$identity]['skills'] ?? []));
                foreach ((array) ($entry['skills'] ?? []) as $skill) {
                    $this->assertContains(
                        (string) $skill,
                        $declared,
                        "{$slug}/CHARACTER.md says identity \"{$identity}\" runs \"{$skill}\", but identities.yaml does not.",
                    );
                }
            }
        }
    }

    public function test_every_identity_reports_to_one_of_the_six_characters(): void
    {
        $identities = (array) ($this->identities()['identities'] ?? []);
        $characters = array_keys((array) ($this->identities()['core_characters'] ?? []));
        $this->assertCount(6, $characters);

        foreach ($identities as $key => $identity) {
            $reportsTo = (string) ($identity['reports_to'] ?? '');
            $this->assertContains($reportsTo, $characters, "Identity \"{$key}\" reports to \"{$reportsTo}\", which is not a core character.");
        }
    }

    public function test_no_skill_is_run_by_two_identities(): void
    {
        $identities = (array) ($this->identities()['identities'] ?? []);

        $owners = [];
        foreach ($identities as $key => $identity) {
            foreach ((array) ($identity['skills'] ?? []) as $skill) {
                $owners[(string) $skill][] = (string) $key;
            }
        }

        foreach ($owners as $skill => $keys) {
            $this->assertCount(1, $keys, "\"{$skill}\" is claimed by more than one identity: ".implode(', ', $keys).'.');
        }
    }

    public function test_the_pillar_list_is_not_declared_twice_with_different_values(): void
    {
        // SkillRegistry::pillars() and Skill::PILLARS both hold the list, and
        // it is the registry's copy that feeds the cockpit and the business
        // map — so a drift between them would be invisible until a card used
        // the pillar the other list was missing.
        $registryPillars = (new SkillRegistry)->pillars();
        $modelPillars = Skill::PILLARS;

        $this->assertSame(
            $modelPillars,
            $registryPillars,
            'SkillRegistry::pillars() and Skill::PILLARS disagree; one of them is feeding the cockpit the wrong list.',
        );
    }

    public function test_the_shipped_cards_all_name_a_real_identity_and_a_real_character(): void
    {
        $identities = (array) ($this->identities()['identities'] ?? []);
        $characters = array_keys((array) ($this->identities()['core_characters'] ?? []));

        foreach ((array) glob($this->packagesRoot().DIRECTORY_SEPARATOR.'skills'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'card.yaml') as $path) {
            $card = (array) Yaml::parseFile((string) $path);
            $slug = (string) ($card['id'] ?? basename(dirname((string) $path)));

            $this->assertArrayHasKey((string) ($card['runs_on'] ?? ''), $identities, "{$slug}: runs_on names an identity that does not exist.");
            $this->assertContains((string) ($card['core_agent'] ?? ''), $characters, "{$slug}: core_agent is not one of the six.");

            // A card MAY give itself a different character from its identity's
            // default — brand-voice-keeper runs on `studio` (which reports to
            // Nexus) but speaks in Hannah's guide voice, and that is the point
            // of the field. What it may not do is diverge silently: the
            // identity's character_note has to say why.
            $reportsTo = (string) ($identities[$card['runs_on']]['reports_to'] ?? '');
            if ((string) $card['core_agent'] !== $reportsTo) {
                $note = mb_strtolower((string) ($identities[$card['runs_on']]['character_note'] ?? ''));
                $this->assertStringContainsString(
                    mb_strtolower((string) $card['core_agent']),
                    $note,
                    "{$slug}: core_agent is {$card['core_agent']} but identity \"{$card['runs_on']}\" reports to {$reportsTo}, "
                        .'and the identity does not explain the override in its character_note.',
                );
            }
        }
    }
}
