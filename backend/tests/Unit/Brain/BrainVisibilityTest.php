<?php

declare(strict_types=1);

namespace Tests\Unit\Brain;

use App\Models\BrainFile;
use App\Services\Brain\BrainManifest;
use App\Services\Brain\BrainVisibility;
use Tests\TestCase;

/**
 * The search visibility policy, against the real manifest.
 *
 * `data_class` was computed on every write, stored, returned by the API and
 * printed into prompt fences — and filtered on by nothing. These are the rules
 * that make the label mean something.
 */
class BrainVisibilityTest extends TestCase
{
    private function manifest(): BrainManifest
    {
        return app(BrainManifest::class);
    }

    public function test_an_agent_declaring_nothing_sees_public_and_internal(): void
    {
        $this->assertSame(
            [BrainFile::DATA_PUBLIC, BrainFile::DATA_INTERNAL],
            BrainVisibility::forAgent([], $this->manifest()),
        );
    }

    public function test_a_declared_confidential_path_widens_the_envelope(): void
    {
        // customer-newsletter really does read reports/newsletter/**.
        $classes = BrainVisibility::forAgent(['brand/voice.md', 'reports/newsletter/**'], $this->manifest());

        $this->assertContains(BrainFile::DATA_CONFIDENTIAL, $classes);
    }

    public function test_an_undeclared_confidential_path_does_not(): void
    {
        // cold-email-drafting's whole declared surface is internal, so the
        // finance summary and the weekly reports stay out of its search.
        $classes = BrainVisibility::forAgent(
            ['business/profile.md', 'offer/offer.md', 'brand/voice.md', 'customers/icp.md'],
            $this->manifest(),
        );

        $this->assertNotContains(BrainFile::DATA_CONFIDENTIAL, $classes);
        $this->assertNotContains(BrainFile::DATA_PERSONAL, $classes);
    }

    public function test_personal_is_never_searchable_by_an_agent_even_when_declared(): void
    {
        // cold-email-drafting declares people/user.md under brain.reads. It
        // still reaches the run — the prompt builder fences it in by name —
        // but search must not widen what the card was reviewed for.
        $classes = BrainVisibility::forAgent(['people/user.md', 'people/team/ana.md'], $this->manifest());

        $this->assertNotContains(BrainFile::DATA_PERSONAL, $classes);
    }

    public function test_a_section_anchor_does_not_defeat_the_lookup(): void
    {
        $this->assertNotContains(
            BrainFile::DATA_PERSONAL,
            BrainVisibility::forAgent(['people/user.md#Never say or offer'], $this->manifest()),
        );
        $this->assertContains(
            BrainFile::DATA_CONFIDENTIAL,
            BrainVisibility::forAgent(['finance/summary.md#Cash'], $this->manifest()),
        );
    }

    public function test_people_see_their_own_personal_files_and_admins_alone_see_confidential(): void
    {
        $member = BrainVisibility::forPerson(false);
        $this->assertContains(BrainFile::DATA_PERSONAL, $member, 'the manifest says personal is visible to the tenant’s own users');
        $this->assertNotContains(BrainFile::DATA_CONFIDENTIAL, $member);

        $this->assertContains(BrainFile::DATA_CONFIDENTIAL, BrainVisibility::forPerson(true));
    }
}
