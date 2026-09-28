<?php

declare(strict_types=1);

namespace Tests\Unit\Brain;

use App\Services\Brain\BrainMarkdown;
use PHPUnit\Framework\TestCase;

/** Pure text semantics the store and the sync service rely on. */
class BrainMarkdownTest extends TestCase
{
    private const DOC = <<<'MD'
---
name: Acme
tags: [a, b]
---

# Business profile

Intro paragraph.

## What we do

We sell widgets.

### Detail

Nested heading stays in the section.

## What makes us different

<!-- missing -->
MD;

    public function test_frontmatter_and_sections_parse(): void
    {
        $this->assertSame(['name' => 'Acme', 'tags' => ['a', 'b']], BrainMarkdown::frontmatter(self::DOC));
        $this->assertStringStartsWith('# Business profile', BrainMarkdown::body(self::DOC));

        $sections = BrainMarkdown::sections(self::DOC);
        $this->assertSame(['What we do', 'What makes us different'], array_keys($sections));
        $this->assertStringContainsString('### Detail', $sections['What we do']);
        $this->assertSame('<!-- missing -->', $sections['What makes us different']);
        $this->assertSame('Business profile', BrainMarkdown::title(self::DOC));
    }

    public function test_upsert_section_preserves_everything_else(): void
    {
        $out = BrainMarkdown::upsertSection(self::DOC, 'What makes us different', 'Nobody else ships in a day.');

        $this->assertStringContainsString("---\nname: Acme\ntags: [a, b]\n---", $out, 'raw frontmatter is byte-preserved');
        $this->assertStringContainsString('Intro paragraph.', $out);
        $this->assertSame('Nobody else ships in a day.', BrainMarkdown::section($out, 'What makes us different'));
        $this->assertStringContainsString('### Detail', BrainMarkdown::section($out, 'what we do') ?? '', 'lookup is case-insensitive');

        $appended = BrainMarkdown::upsertSection($out, 'Where we are today', 'Three people.');
        $this->assertSame(['What we do', 'What makes us different', 'Where we are today'], array_keys(BrainMarkdown::sections($appended)));
    }

    public function test_managed_blocks_replace_in_place(): void
    {
        $block = BrainMarkdown::managedBlock('profile.what_we_do', 'Projected line.');
        $doc = BrainMarkdown::appendToSection(self::DOC, 'What we do', $block);

        $this->assertTrue(BrainMarkdown::hasManagedBlock($doc, 'profile.what_we_do'));
        $this->assertStringContainsString("We sell widgets.\n\n### Detail", $doc, 'human prose before the block survives');

        $replaced = BrainMarkdown::replaceManagedBlock($doc, 'profile.what_we_do', 'New $1 line with \\ backslash.');
        $this->assertStringContainsString('New $1 line with \\ backslash.', $replaced, 'replacement text is literal');
        $this->assertStringNotContainsString('Projected line.', $replaced);
        $this->assertStringContainsString('We sell widgets.', $replaced);
        $this->assertSame($doc, BrainMarkdown::replaceManagedBlock($doc, 'unknown.key', 'x'), 'unknown block is a no-op');
    }

    public function test_prose_length_ignores_comments_and_markers(): void
    {
        $this->assertSame(0, BrainMarkdown::proseLength('<!-- missing -->'));
        $this->assertSame(0, BrainMarkdown::proseLength(BrainMarkdown::managedBlock('k', '')));
        $this->assertSame(11, BrainMarkdown::proseLength(BrainMarkdown::managedBlock('k', "Hello\n\nworld")));
    }

    public function test_wholly_managed_detection(): void
    {
        $managed = "# Title\n\n## Facts\n\n".BrainMarkdown::managedBlock('affiliate.facts', 'Commission: 30%')."\n";
        $this->assertTrue(BrainMarkdown::isWhollyManaged($managed));

        $mixed = $managed."\nA human wrote this.\n";
        $this->assertFalse(BrainMarkdown::isWhollyManaged($mixed));
        $this->assertFalse(BrainMarkdown::isWhollyManaged("## Facts\n\nNo blocks at all."));
    }

    public function test_with_frontmatter_round_trips(): void
    {
        $doc = BrainMarkdown::withFrontmatter(['pricing' => '$99/mo', 'links' => []], "## Pricing\n\nText\n");
        $this->assertStringStartsWith("---\n", $doc);
        $this->assertSame(['pricing' => '$99/mo', 'links' => []], BrainMarkdown::frontmatter($doc));
        $this->assertSame("## Pricing\n\nText\n", BrainMarkdown::body($doc));
        $this->assertSame('plain', BrainMarkdown::withFrontmatter([], 'plain'));
    }
}
