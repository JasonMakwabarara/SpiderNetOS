<?php

declare(strict_types=1);

namespace Tests\Feature\Launch;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * GET /api/launch/jurisdictions/{code}/checklist — the country pack merged
 * with ComplianceRadar's five-rule heuristic (plan D7 §5): UK / ZA / ZW,
 * structure filtering, the ECCTA identity item, "Atlas never files", the
 * staleness awareness item and the disclaimer on every payload.
 */
class JurisdictionChecklistTest extends LaunchTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_uk_pack_lists_its_checklist_with_owners_links_and_the_disclaimer(): void
    {
        Carbon::setTestNow('2026-10-01');

        $data = $this->actingAsOwner()
            ->getJson('/api/launch/jurisdictions/uk/checklist')
            ->assertOk()
            ->json('data');

        $this->assertSame('uk', $data['jurisdiction']['code']);
        $this->assertSame('United Kingdom', $data['jurisdiction']['name']);
        $this->assertSame('GBP', $data['jurisdiction']['currency']);
        $this->assertFalse($data['jurisdiction']['stale']);
        $this->assertStringContainsString('Not legal or financial advice', $data['disclaimer']);
        $this->assertSame([], $data['awareness']);

        $ids = array_column($data['items'], 'id');
        $this->assertContains('uk_choose_structure', $ids);
        $this->assertContains('uk_verify_identity', $ids);

        $identity = collect($data['items'])->firstWhere('id', 'uk_verify_identity');
        $this->assertSame('founder', $identity['owner']);
        $this->assertNotEmpty($identity['links']);
        // Structure unknown → the company-only item stays, flagged conditional.
        $this->assertTrue($identity['conditional']);

        $atlasItem = collect($data['items'])->firstWhere('id', 'uk_atlas_check_identity_status');
        $this->assertSame('atlas', $atlasItem['owner']);
        $this->assertStringContainsString('never', $atlasItem['detail']);
    }

    public function test_a_pack_older_than_its_review_window_raises_an_awareness_item(): void
    {
        Carbon::setTestNow('2027-09-01');

        $data = $this->actingAsOwner()
            ->getJson('/api/launch/jurisdictions/uk/checklist')
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['jurisdiction']['stale']);
        $this->assertSame('uk_rules_may_have_changed', $data['awareness'][0]['id']);
        $this->assertSame('awareness', $data['awareness'][0]['severity']);
    }

    public function test_the_founders_answers_filter_the_list_and_merge_the_radar_obligations(): void
    {
        Carbon::setTestNow('2026-10-01');

        $this->startLaunch('uk');
        $this->answer('jurisdiction', 'uk');
        $this->answer('legal_structure', 'A sole trader for now.');
        $this->answer('handles_personal_data', 'Yes — names, emails and phone numbers.');
        $this->answer('pricing_model', 'GBP 100 per visit, billed monthly.');

        $data = $this->actingAsOwner()
            ->getJson('/api/launch/jurisdictions/uk/checklist')
            ->assertOk()
            ->json('data');

        $ids = array_column($data['items'], 'id');
        // Sole trader → the company-only incorporation item drops out.
        $this->assertNotContains('uk_incorporate', $ids);
        $this->assertContains('uk_choose_structure', $ids);

        // ComplianceRadar's heuristic rides alongside the country pack.
        $radar = array_values(array_filter($data['items'], fn (array $i) => ($i['source'] ?? '') === 'radar'));
        $this->assertNotEmpty($radar);
        $this->assertContains('radar_privacy_basics', array_column($radar, 'id'));
    }

    public function test_south_africa_and_zimbabwe_packs_load(): void
    {
        foreach (['za' => 'ZAR', 'zw' => 'USD'] as $code => $ignored) {
            $data = $this->actingAsOwner()
                ->getJson("/api/launch/jurisdictions/{$code}/checklist")
                ->assertOk()
                ->json('data');

            $this->assertSame($code, $data['jurisdiction']['code']);
            $this->assertNotEmpty($data['items']);
            $this->assertNotEmpty($data['jurisdiction']['currency']);
            $this->assertStringContainsString('Not legal or financial advice', $data['disclaimer']);
        }
    }

    public function test_an_unknown_country_is_404(): void
    {
        $this->actingAsOwner()->getJson('/api/launch/jurisdictions/xx/checklist')->assertNotFound();
        // Non-matching codes never reach a query at all.
        $this->actingAsOwner()->getJson('/api/launch/jurisdictions/NOT-A-CODE/checklist')->assertNotFound();
    }
}
