<?php

declare(strict_types=1);

namespace Tests\Feature\Skills;

use App\Models\Skill;
use App\Models\SkillRelation;
use App\Services\Skills\SkillCatalogue;
use App\Services\Skills\SkillRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * skills:seed projects the card folders into skills + skill_relations,
 * idempotently by card_hash, and the relations carry to_slug for known cards
 * and to_ref for identities, products, principles and roles.
 */
class SkillCatalogueSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SkillRegistry::flush();
    }

    private function catalogue(): SkillCatalogue
    {
        return new SkillCatalogue(new SkillRegistry());
    }

    public function test_seed_is_idempotent(): void
    {
        $first = $this->catalogue()->seed();

        $this->assertSame(8, $first['created']);
        $this->assertSame(0, $first['updated']);
        $this->assertSame(0, $first['unchanged']);
        $this->assertGreaterThan(40, $first['relations']);
        $this->assertSame(8, Skill::count());
        $relations = SkillRelation::count();
        $this->assertSame($first['relations'], $relations);

        $second = $this->catalogue()->seed();

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 8], array_intersect_key($second, array_flip(['created', 'updated', 'unchanged'])));
        $this->assertSame(0, $second['relations'], 'unchanged cards do not rewrite their edges');
        $this->assertSame(8, Skill::count());
        $this->assertSame($relations, SkillRelation::count());
    }

    public function test_seed_rewrites_a_card_whose_hash_changed(): void
    {
        $this->catalogue()->seed();
        Skill::where('slug', 'cold-email-drafting')->update(['card_hash' => 'stale', 'name' => 'Old name']);
        $before = SkillRelation::where('from_slug', 'cold-email-drafting')->count();

        $summary = $this->catalogue()->seed();

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(7, $summary['unchanged']);
        $this->assertSame('Cold Email Drafting', Skill::find('cold-email-drafting')->name);
        $this->assertSame($before, SkillRelation::where('from_slug', 'cold-email-drafting')->count());
    }

    public function test_skill_rows_carry_the_card_and_prompt(): void
    {
        $this->catalogue()->seed();

        $skill = Skill::find('cold-email-drafting');
        $this->assertSame('1.0.0', $skill->version);
        $this->assertSame('sales', $skill->pillar);
        $this->assertSame('sales', $skill->map_function);
        $this->assertSame('Sales › Outreach writing', $skill->map_node);
        $this->assertSame('growth', $skill->runs_on);
        $this->assertSame('nexus', $skill->core_agent);
        $this->assertSame('sales-crm', $skill->pack_id);
        $this->assertSame('agent', $skill->runKind());
        $this->assertSame(['brain.read', 'drafts.save', 'drafts.submit_for_review'], $skill->tools());
        $this->assertSame('draft_sequence', $skill->card['outputs'][0]['kind']);
        $this->assertStringContainsString('TASK: Draft one cold email sequence.', $skill->prompt_md);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $skill->card_hash);

        $this->assertSame('sales', Skill::find('prospect-research-analysis')->map_function, 'pillar sales → map function sales');
        $this->assertSame('sales', Skill::find('meeting-booking')->map_function, 'pillar deals → map function sales');
        $this->assertNull(Skill::find('prospect-research-analysis')->pack_id);
        $this->assertSame('marketing', Skill::find('brand-voice-keeper')->map_function);
        $this->assertSame('interview', Skill::find('icp-definition')->runKind());
    }

    public function test_relations_point_at_cards_or_external_refs(): void
    {
        $this->catalogue()->seed();

        $edges = Skill::find('cold-email-drafting')->edges()->get();

        $breaksInto = $edges->where('relation', SkillRelation::BREAKS_INTO);
        $this->assertCount(5, $breaksInto);
        $this->assertSame('Sequence Writer', $breaksInto->first()->meta['name']);
        $this->assertTrue($breaksInto->first()->isExternal());
        $this->assertStringStartsWith('capability:', $breaksInto->first()->to_ref);

        $buildsOn = $edges->where('relation', SkillRelation::BUILDS_ON)->values();
        $this->assertSame('icp-definition', $buildsOn[0]->to_slug);
        $this->assertSame('prospect-research-analysis', $buildsOn[1]->to_slug);
        $this->assertSame('brain:brand/voice.md#Tone', $buildsOn[2]->to_ref);
        $this->assertContains('principle:value-cost-gap', $buildsOn->pluck('to_ref')->all());

        $replaces = $edges->where('relation', SkillRelation::REPLACES)->values();
        $this->assertCount(3, $replaces);
        $this->assertSame(['what' => "An SDR's core written output", 'cost' => '$60–80k/yr', 'kind' => 'salary', 'note' => 'The sequence writing, not the sending or the judgement.'], $replaces[0]->meta);
        $this->assertSame('undone', $replaces[2]->meta['kind']);

        $handsOff = $edges->where('relation', SkillRelation::HANDS_OFF_TO)->values();
        $this->assertCount(4, $handsOff);
        $this->assertSame('follow-up-drafting', $handsOff[0]->to_slug);
        $this->assertSame('inbox-triage-reply-classifier', $handsOff[1]->to_slug);
        $this->assertSame('meeting-booking', $handsOff[2]->to_slug);
        $this->assertNull($handsOff[3]->to_slug);
        $this->assertSame('identity:richard', $handsOff[3]->to_ref);
        $this->assertStringContainsString('LinkedIn', $handsOff[3]->meta['when']);

        // Planned roster skills are edges with to_ref, flagged planned.
        $richardRunner = SkillRelation::where('from_slug', 'linkedin-outreach-specialist')
            ->where('relation', SkillRelation::HANDS_OFF_TO)
            ->where('to_ref', 'skill:linkedin-campaign-runner')
            ->first();
        $this->assertNotNull($richardRunner);
        $this->assertTrue($richardRunner->meta['planned']);

        // Every to_slug points at a seeded card (the FK holds).
        $dangling = SkillRelation::whereNotNull('to_slug')->whereNotIn('to_slug', Skill::pluck('slug'))->count();
        $this->assertSame(0, $dangling);
    }

    public function test_artisan_commands(): void
    {
        $this->artisan('skills:validate')->assertExitCode(0);
        $this->artisan('skills:validate', ['slug' => 'cold-email-drafting'])->assertExitCode(0);
        $this->artisan('skills:validate', ['slug' => 'no-such-card'])->assertExitCode(1);

        $this->artisan('skills:seed')->assertExitCode(0);
        $this->assertSame(8, Skill::count());
        $this->artisan('skills:seed')->assertExitCode(0);
        $this->assertSame(8, Skill::count());

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SkillCatalogueSeeder'])->assertExitCode(0);
        $this->assertSame(8, Skill::count());
    }
}
