<?php

declare(strict_types=1);

namespace Tests\Unit\Brain;

use App\Services\Brain\BrainManifest;
use PHPUnit\Framework\TestCase;

/**
 * packages/brain/manifest.yaml is the source of truth for the Knowledge
 * brain's layout: it must load, every short key must resolve to a declared
 * path (and section), and folder patterns must match concrete paths.
 * Pure — no Laravel container, no database.
 */
class BrainManifestTest extends TestCase
{
    private static function manifestPath(): string
    {
        return dirname(__DIR__, 4).'/packages/brain/manifest.yaml';
    }

    private function manifest(): BrainManifest
    {
        return new BrainManifest(self::manifestPath());
    }

    public function test_manifest_loads_with_folders_and_canonical_paths(): void
    {
        $m = $this->manifest();

        $this->assertSame(1, $m->version());

        $folders = array_keys($m->folders());
        $this->assertSame('business', $folders[0], 'folders are ordered by `order`');
        $this->assertContains('people', $folders);
        $this->assertContains('workspaces', $folders);
        $this->assertTrue((bool) ($m->folders()['workspaces']['agent_private'] ?? false));

        $canonical = $m->canonicalPaths();
        foreach (['business/profile.md', 'business/alignment.md', 'offer/offer.md', 'customers/icp.md', 'customers/objections.md', 'brand/voice.md', 'processes/follow-ups.md', 'programs/affiliate.md', 'finance/summary.md', 'market/comparables.md', 'people/user.md'] as $path) {
            $this->assertContains($path, $canonical, "{$path} is a canonical brain file");
        }
        $this->assertNotContains('processes/<slug>.md', $canonical, 'patterns are not canonical files');
    }

    public function test_every_key_resolves_to_a_declared_path_and_section(): void
    {
        $m = $this->manifest();
        $keys = $m->keys();
        $this->assertNotEmpty($keys);

        foreach ($keys as $key => $target) {
            $resolved = $m->resolveKey($key);
            $spec = $m->fileSpec($resolved['path']);
            $this->assertNotNull($spec, "key {$key} → {$resolved['path']} is declared");

            if ($resolved['section'] !== null) {
                $this->assertNotNull(
                    $m->sectionSpec($resolved['path'], $resolved['section']),
                    "key {$key} → section \"{$resolved['section']}\" exists in {$resolved['path']}",
                );
            }
        }

        $this->assertSame(['path' => 'brand/voice.md', 'section' => 'Tone'], $m->resolveKey('voice.tone'));
        $this->assertSame(['path' => 'business/alignment.md', 'section' => null], $m->resolveKey('business.alignment'));
        $this->assertSame(['path' => 'offer/offer.md', 'section' => 'Pricing'], $m->resolveKey('offer/offer.md#Pricing'), 'literal path#section is accepted');
    }

    public function test_unknown_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manifest()->resolveKey('nope.nothing');
    }

    public function test_folder_patterns_match_concrete_paths(): void
    {
        $m = $this->manifest();

        $this->assertSame('Follow-up rules', $m->fileSpec('processes/follow-ups.md')['title'], 'exact path wins over the processes pattern');
        $this->assertSame('Process', $m->fileSpec('processes/lead-intake.md')['title']);
        $this->assertTrue((bool) $m->fileSpec('processes/lead-intake.md')['pattern']);
        $this->assertSame('Team member', $m->fileSpec('people/team/jane-doe.md')['title']);
        $this->assertSame('Notes', $m->fileSpec('notes/anything.md')['title']);
        $this->assertSame('Notes', $m->fileSpec('notes/deep/nested/idea.md')['title']);
        $this->assertSame('Research', $m->fileSpec('notes/research/2026/competitors.md')['title'], 'the more specific pattern wins');
        $this->assertSame('ZetKai notes', $m->fileSpec('notes/zetkai/daily.md')['title']);
        $this->assertSame('Agent scratch', $m->fileSpec('workspaces/growth/scratch/ideas.md')['title']);
        $this->assertTrue($m->isAgentPrivate('workspaces/growth/drafts/email-1.md'));
        $this->assertFalse($m->isAgentPrivate('people/user.md'));
        $this->assertNull($m->fileSpec('random/file.md'));
        $this->assertNull($m->fileSpec('processes/nested/too-deep.md'), '<slug> matches one segment only');
    }

    public function test_sections_questions_data_class_and_staleness(): void
    {
        $m = $this->manifest();

        $this->assertSame(['What we do', 'What makes us different'], $m->requiredSections('business/profile.md'));
        $this->assertSame(['Steps'], $m->requiredSections('processes/anything.md'));
        $this->assertSame([], $m->requiredSections('notes/x.md'));

        $this->assertStringContainsString('How should we sound', (string) $m->question('brand/voice.md', 'Tone'));
        $this->assertSame($m->question('business/profile.md', 'What we do'), $m->question('business/profile.md', null), 'null section → first required question');
        $this->assertNull($m->question('people/user.md', 'What I always edit'), 'inferred sections are never asked');
        $this->assertNull($m->question('business/profile.md', 'Not a section'));

        $this->assertSame('personal', $m->dataClass('people/user.md'));
        $this->assertSame('confidential', $m->dataClass('finance/summary.md'));
        $this->assertSame('internal', $m->dataClass('unknown/file.md'));

        $this->assertSame(14, $m->staleAfterDays('finance/summary.md'));
        $this->assertNull($m->staleAfterDays('notes/foo.md'));
        $this->assertNull($m->staleAfterDays('not/declared.md'));

        $this->assertSame('Business profile', $m->title('business/profile.md'));
        $this->assertSame('Lead intake', $m->title('processes/lead-intake.md'), 'pattern files are titled from the basename');
    }

    public function test_readiness_order_and_interview_mapping(): void
    {
        $m = $this->manifest();

        $order = $m->readinessOrder();
        $this->assertSame('business/profile.md', $order[0]);
        $this->assertContains('people/user.md', $order);
        foreach ($order as $path) {
            $this->assertNotNull($m->fileSpec($path), "{$path} in readiness_order is declared");
        }

        $this->assertContains(['path' => 'brand/voice.md', 'section' => 'Tone'], $m->interviewTargets('preferred_tone'));
        $targets = array_column($m->interviewTargets('core_offer'), 'path');
        $this->assertContains('business/profile.md', $targets);
        $this->assertContains('offer/offer.md', $targets);
        $this->assertSame([], $m->interviewTargets('not_a_question'));
    }

    public function test_pattern_to_regex(): void
    {
        $this->assertMatchesRegularExpression(BrainManifest::patternToRegex('processes/<slug>.md'), 'processes/lead-intake.md');
        $this->assertDoesNotMatchRegularExpression(BrainManifest::patternToRegex('processes/<slug>.md'), 'processes/a/b.md');
        $this->assertMatchesRegularExpression(BrainManifest::patternToRegex('notes/**'), 'notes/a/b/c.md');
        $this->assertDoesNotMatchRegularExpression(BrainManifest::patternToRegex('notes/**'), 'reports/a.md');
        $this->assertMatchesRegularExpression(BrainManifest::patternToRegex('workspaces/<agent>/scratch/**'), 'workspaces/growth/scratch/x.md');
    }
}
