<?php

declare(strict_types=1);

namespace Tests\Unit\Brain;

use App\Services\Brain\BrainGapAnalyzer;
use App\Services\Brain\BrainManifest;
use App\Services\Brain\BrainMarkdown;
use App\Services\Brain\BrainStore;
use App\Services\EventStore;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Gap analysis is pure over (files, requires) — the analyzer is built with
 * the real manifest and a store that is never touched.
 */
class BrainGapAnalyzerTest extends TestCase
{
    private BrainManifest $manifest;

    private BrainGapAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifest = new BrainManifest(dirname(__DIR__, 4).'/packages/brain/manifest.yaml');
        $store = new BrainStore($this->manifest, $this->createMock(EventStore::class));
        $this->analyzer = new BrainGapAnalyzer($store, $this->manifest);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static function file(string $content, ?string $updatedAt = null): array
    {
        return ['content' => $content, 'version' => 1, 'updated_at' => $updatedAt ?? Carbon::now()->toIso8601String()];
    }

    public function test_missing_file_yields_one_gap_per_required_section_with_the_question(): void
    {
        $gaps = $this->analyzer->analyze(
            ['business/profile.md' => null],
            $this->analyzer->normalizeRequires([['path' => 'business/profile.md']]),
        );

        $this->assertCount(2, $gaps);
        $this->assertSame('missing_file', $gaps[0]['reason']);
        $this->assertSame('What we do', $gaps[0]['section']);
        $this->assertSame('What is your company and what do you do, in one line?', $gaps[0]['question']);
        $this->assertSame('What makes us different', $gaps[1]['section']);
    }

    public function test_missing_short_and_marker_only_sections(): void
    {
        $content = "# Offer\n\n## Products and services\n\n".str_repeat('We sell widgets to plumbers. ', 4)
            ."\n\n## Pricing\n\nCheap.\n\n## Proof\n\n<!-- missing -->\n";

        $gaps = $this->analyzer->analyze(
            ['offer/offer.md' => self::file($content)],
            $this->analyzer->normalizeRequires([['path' => 'offer/offer.md']]),
        );

        $byReason = [];
        foreach ($gaps as $gap) {
            $byReason[$gap['reason']][] = $gap['section'];
        }
        $this->assertSame(['Pricing'], $byReason['too_short'] ?? []);
        $this->assertSame(['Proof'], $byReason['missing_section'] ?? [], 'a marker-only section counts as missing');
        $this->assertArrayNotHasKey('missing_file', $byReason);
        $this->assertArrayNotHasKey('stale', $byReason);
    }

    public function test_requires_accept_keys_paths_and_card_entries(): void
    {
        $targets = $this->analyzer->normalizeRequires([
            'voice.tone',
            ['key' => 'voice.do_dont'],
            ['path' => 'customers/icp.md', 'sections' => ['Buying triggers']],
            'business/profile.md#What we do',
            ['path' => 'business/profile.md'],
        ]);

        $this->assertSame(['Tone', 'Do and don\'t'], $targets['brand/voice.md']);
        $this->assertSame(['Buying triggers'], $targets['customers/icp.md']);
        $this->assertSame([], $targets['business/profile.md'], 'a bare path subsumes the narrower section entry');

        $gaps = $this->analyzer->analyze(['brand/voice.md' => null, 'customers/icp.md' => null, 'business/profile.md' => null], $targets);
        $sections = array_map(static fn (array $g) => $g['path'].'#'.$g['section'], $gaps);
        $this->assertContains('brand/voice.md#Tone', $sections);
        $this->assertContains('brand/voice.md#Do and don\'t', $sections);
        $this->assertContains('customers/icp.md#Buying triggers', $sections);
        $this->assertContains('business/profile.md#What we do', $sections);
    }

    public function test_stale_file_is_reported_once(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');
        $fresh = "## Summary\n\nCash is fine.\n";

        $gaps = $this->analyzer->analyze(
            ['finance/summary.md' => self::file($fresh, '2026-08-01 00:00:00')],
            $this->analyzer->normalizeRequires([['path' => 'finance/summary.md']]),
        );
        $this->assertCount(1, $gaps);
        $this->assertSame('stale', $gaps[0]['reason']);
        $this->assertNull($gaps[0]['section']);

        $gaps = $this->analyzer->analyze(
            ['finance/summary.md' => self::file($fresh, '2026-09-10 00:00:00')],
            $this->analyzer->normalizeRequires([['path' => 'finance/summary.md']]),
        );
        $this->assertSame([], $gaps);
    }

    public function test_inferred_sections_are_never_asked(): void
    {
        $gaps = $this->analyzer->analyze(
            ['people/user.md' => self::file("## Who I am\n\nJason, founder of Apex Synchronia, runs three businesses.\n")],
            $this->analyzer->normalizeRequires([['path' => 'people/user.md', 'sections' => ['What I always edit', 'Who I am']]]),
        );

        $this->assertSame([], $gaps);
    }

    public function test_readiness_counts_required_sections_across_the_order(): void
    {
        $profile = "# Business profile\n\n## What we do\n\n".str_repeat('Clinic scheduling software for small practices. ', 3)
            ."\n\n## What makes us different\n\n".str_repeat('No-show reduction that actually works. ', 3)."\n";

        $order = $this->manifest->readinessOrder();
        $files = array_fill_keys($order, null);
        $files['business/profile.md'] = self::file($profile);

        $readiness = $this->analyzer->readinessFor($files, $order);

        $totalRequired = 0;
        foreach ($order as $path) {
            foreach ($this->manifest->requiredSections($path) as $section) {
                if (empty($this->manifest->sectionSpec($path, $section)['inferred'])) {
                    $totalRequired++;
                }
            }
        }
        $this->assertSame((int) round(100 * 2 / $totalRequired), $readiness['pct']);
        $this->assertSame('business/profile.md', $readiness['files'][0]['path']);
        $this->assertSame('filled', $readiness['files'][0]['status']);
        $this->assertNull($readiness['files'][0]['ask_prompt']);
        $this->assertSame('missing', $readiness['files'][1]['status']);
        $this->assertNotNull($readiness['files'][1]['ask_prompt']);
        $this->assertSame('Offer', $readiness['files'][1]['title']);
    }

    public function test_status_for_file_content(): void
    {
        $spec = $this->manifest->fileSpec('customers/icp.md');
        $long = str_repeat('Owner-operated clinics with two to five doctors. ', 3);

        $this->assertSame('missing', BrainGapAnalyzer::statusFor(null, $spec));
        $this->assertSame('missing', BrainGapAnalyzer::statusFor("## Who we sell to\n\n<!-- missing -->\n", $spec));
        $this->assertSame('partial', BrainGapAnalyzer::statusFor("## Who we sell to\n\n{$long}\n", $spec));
        $this->assertSame('filled', BrainGapAnalyzer::statusFor("## Who we sell to\n\n{$long}\n\n## Who we do not sell to\n\nSolo practitioners, hospital groups and anyone who wants a free trial forever.\n", $spec));
        $this->assertSame('filled', BrainGapAnalyzer::statusFor("Free-form note.\n", $this->manifest->fileSpec('notes/a.md')));
        $this->assertSame('filled', BrainGapAnalyzer::statusFor(BrainMarkdown::managedBlock('k', 'text'), null));
    }
}
