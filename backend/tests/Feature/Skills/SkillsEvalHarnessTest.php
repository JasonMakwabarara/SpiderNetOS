<?php

declare(strict_types=1);

namespace Tests\Feature\Skills;

use App\Services\Skills\Eval\EvalCase;
use App\Services\Skills\Eval\EvalRunner;
use App\Services\Skills\Eval\PropertyChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Skill eval harness (plan D8 #14): loads Stream B1's real
 * packages/skills/cold-email-drafting/evals/cases.yaml, replays a synthetic
 * cases file with stored outputs through SkillOutputValidator + the property
 * checks, and prints/writes the report.
 */
class SkillsEvalHarnessTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'cold-email-drafting';

    private string $synthetic;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->synthetic = sys_get_temp_dir().'/sn-eval-'.uniqid().'.yaml';
        file_put_contents($this->synthetic, self::syntheticCases());
    }

    protected function tearDown(): void
    {
        @unlink($this->synthetic);
        foreach ($this->written as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private static function syntheticCases(): string
    {
        $good = json_encode([
            'campaign' => 'cafes-q4',
            'segment' => 'Cape Town cafés with 1–3 sites',
            'angle' => 'Owners still close month-end at the kitchen table; we close the books by the 5th.',
            'steps' => [
                ['step' => 1, 'beat' => 'problem', 'subjects' => ['Your month-end, closed by the 5th', 'Kitchen-table bookkeeping'], 'body' => 'Morning. Most café owners we meet still do month-end at the kitchen table, long after close. We close your books by the 5th, every month, or that month is free.', 'send_day' => 0, 'cta' => 'Worth a 15-minute look? https://cal.example/northbeam/15', 'personalisation_slot' => '{{recent_review}}'],
                ['step' => 2, 'beat' => 'proof', 'subjects' => ['Café Roux: 9 days to 2', 'One page, by the 5th'], 'body' => 'Café Roux cut month-end from 9 days to 2 once the books were closed by the 5th. Forty-one cafés are on the books today and none has left for a competitor in eighteen months.', 'send_day' => 3, 'cta' => 'Want the one-page summary they get?'],
                ['step' => 3, 'beat' => 'close', 'subjects' => ['Last note from the bookkeeper', 'Closing the loop'], 'body' => 'Last one from me. If month-end is still eating your evenings, fifteen minutes with the bookkeeper who would actually do your books is the fastest way to find out.', 'send_day' => 7, 'cta' => 'Pick a slot: https://cal.example/northbeam/15'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $bad = json_encode([
            'campaign' => 'cafes-bad',
            'steps' => [
                ['step' => 1, 'beat' => 'close', 'subjects' => ['Hi'], 'body' => 'Guaranteed results! Follow us https://instagram.com/northbeam — get 20% off and a discount on setup.', 'send_day' => 0, 'cta' => 'Reply now'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<YAML
version: 1
skill: cold-email-drafting
fixtures:
  base_brain: &base_brain
    offer/offer.md: |
      ---
      pricing: "From R1,800 per month, no setup fee"
      proof_points:
        - "Café Roux cut month-end from 9 days to 2 (2025)"
        - "41 cafés on the books, none lost to a competitor in 18 months"
      links:
        - https://northbeam.example/cafes
        - https://cal.example/northbeam/15
      ---
      ## Proof
      Café Roux cut month-end from 9 days to 2. 41 cafés on the books; none lost to a competitor in 18 months.
    brand/voice.md: |
      ## Do and don't
      Do say "your books". Don't say "leverage", "solutions", "seamless", "game-changer". Never exclamation marks.
    people/user.md: |
      ## Never say or offer
      Never offer a discount. Never compare us to a named competitor.
cases:
  - id: good_sequence
    fixture_brain: *base_brain
    inputs: { campaign: cafes-q4, steps: 3 }
    expected_raw: '{$good}'
    expect:
      properties:
        - valid_json
        - schema_valid
        - steps_count:3
        - beats_in_order:problem,proof,close
        - subjects_per_step:2
        - single_cta_per_step
        - no_unverified_figures
        - links_allowlisted
        - no_banned_phrases
        - mentions_proof_point
        - respects_never_say
        - personalisation_slot_present:1
        - { type: max_length, path: steps.0.body, max: 900 }
      judge:
        - "Step 1 opens with an observation, not a greeting."
  - id: bad_sequence
    fixture_brain: *base_brain
    inputs: { campaign: cafes-bad, steps: 3 }
    expected_raw: '{$bad}'
    expect:
      properties:
        - schema_valid
        - steps_count:3
        - beats_in_order:problem,proof,close
        - no_unverified_figures
        - links_allowlisted
        - no_banned_phrases
        - respects_never_say
        - { type: enum, path: steps.0.beat, values: [problem] }
  - id: needs_live
    fixture_brain: *base_brain
    inputs: { campaign: later }
    expect:
      properties:
        - schema_valid
YAML;
    }

    public function test_stream_b1_cases_load_with_anchors_and_judges(): void
    {
        $file = app(EvalRunner::class)->casesFile(self::SLUG);
        $this->assertFileExists($file, 'packages/skills/cold-email-drafting/evals/cases.yaml (Stream B1)');

        $cases = EvalCase::loadAll($file);

        $this->assertGreaterThanOrEqual(8, count($cases));
        $first = $cases[0];
        $this->assertSame('happy_path_cafes', $first->id);
        $this->assertArrayHasKey('business/profile.md', $first->fixtureBrain, 'YAML anchors resolve the shared fixture brain');
        $this->assertArrayHasKey('offer/offer.md', $first->fixtureBrain);
        $this->assertSame('cafes-q4', $first->inputs['campaign']);
        $this->assertContains('steps_count:3', $first->properties);
        $this->assertNotEmpty($first->judges);
        $this->assertNull($first->expectedRaw);
        $this->assertSame('From R1,800 per month, no setup fee', $first->fixtureFrontmatter('offer/offer.md')['pricing']);
        $this->assertStringContainsString('Café Roux', (string) $first->fixtureSection('offer/offer.md', 'Proof'));

        $merged = collect($cases)->firstWhere('id', 'brain_content_is_data');
        $this->assertNotNull($merged);
        $this->assertStringContainsString('IGNORE ALL PREVIOUS INSTRUCTIONS', $merged->fixtureBrain['customers/objections.md'], 'merge keys (<<) overlay the base brain');
        $this->assertArrayHasKey('brand/voice.md', $merged->fixtureBrain);
    }

    public function test_deterministic_replay_of_b1_cases_skips_without_stored_outputs_and_writes_a_report(): void
    {
        $report = app(EvalRunner::class)->run(self::SLUG, ['deterministic' => true]);
        $s = $report['summary'];

        $this->assertSame('deterministic', $report['mode']);
        $this->assertSame($s['declared'], $s['skipped']);
        $this->assertSame(0, $s['executed']);
        $this->assertSame(0, $s['failed']);
        // The founding defect: zero failures over zero executed cases is not a
        // pass, and the summary must not let it read as one.
        $this->assertFalse($s['complete']);
        $this->assertSame(["{$s['declared']} of {$s['declared']} case(s) did not execute"], $s['incomplete_reasons']);
        $this->assertArrayNotHasKey('pass_rate', $s);
        $this->assertSame('no expected_raw — needs --live', $report['cases'][0]['note']);
        Storage::disk('local')->assertExists($report['report_path']);
    }

    public function test_deterministic_replay_scores_stored_outputs_through_the_validator_and_properties(): void
    {
        $report = app(EvalRunner::class)->run(self::SLUG, ['deterministic' => true, 'prompt_version' => '1.0.0', 'cases_file' => $this->synthetic]);

        $this->assertSame([
            'declared' => 3, 'executed' => 2, 'passed' => 1, 'failed' => 1, 'skipped' => 1, 'unavailable' => 0,
            'complete' => false, 'incomplete_reasons' => ['1 of 3 case(s) did not execute'],
        ], $report['summary']);

        $good = $report['cases'][0];
        $this->assertSame('passed', $good['status'], json_encode($good, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertTrue($good['validator']['ok'], 'SkillOutputValidator accepts the stored output: '.implode('; ', $good['validator']['errors']));
        $this->assertSame([], array_filter($good['properties'], fn (array $p) => $p['status'] === 'failed'));
        $this->assertSame(['Step 1 opens with an observation, not a greeting.'], $good['judges']);
        // Listed, carried, and not run — which the result has to say.
        $this->assertSame('not_executed', $good['judges_status']);

        $bad = $report['cases'][1];
        $this->assertSame('failed', $bad['status']);
        $this->assertFalse($bad['validator']['ok']);
        $this->assertNotEmpty($bad['validator']['errors']);
        $failedTypes = array_column(array_values(array_filter($bad['properties'], fn (array $p) => $p['status'] === 'failed')), 'type');
        $this->assertSame(['schema_valid', 'steps_count', 'beats_in_order', 'no_unverified_figures', 'links_allowlisted', 'no_banned_phrases', 'respects_never_say', 'enum'], $failedTypes);
        $detail = implode(' | ', array_column($bad['properties'], 'detail'));
        $this->assertStringContainsString('20%', $detail);
        $this->assertStringContainsString('instagram.com', $detail);
        $this->assertStringContainsString('guaranteed', $detail);
        $this->assertStringContainsString('discount', $detail);

        $this->assertSame('skipped', $report['cases'][2]['status']);

        Storage::disk('local')->assertExists($report['report_path']);
        $written = json_decode(Storage::disk('local')->get($report['report_path']), true);
        $this->assertSame('1.0.0', $written['prompt_version']);
        $this->assertSame(2, $written['summary']['executed']);
        $this->assertFalse($written['summary']['complete']);
    }

    public function test_property_checker_array_form_and_string_form(): void
    {
        $checker = new PropertyChecker;
        $case = EvalCase::fromArray(['id' => 'x', 'fixture_brain' => ['offer/offer.md' => "---\nlinks:\n  - https://cal.example/x\n---\n## Proof\nAcme booked 14 demos in 30 days."]]);
        $output = ['steps' => [['beat' => 'problem', 'body' => 'Acme booked 14 demos in 30 days. See https://cal.example/x', 'subjects' => ['a', 'b'], 'cta' => 'Chat?']], 'classification' => 'hot'];

        $ok = $checker->check([
            ['type' => 'has_key', 'key' => 'steps.0.body'],
            ['type' => 'count', 'path' => 'steps', 'equals' => 1],
            ['type' => 'enum', 'path' => 'classification', 'values' => ['hot']],
            ['type' => 'cites_fact', 'from_brain' => 'offer/offer.md'],
            ['type' => 'cites_fact', 'fact' => '14 demos'],
            ['type' => 'max_length', 'path' => 'steps.0.body', 'max' => 12, 'unit' => 'words'],
            ['type' => 'matches', 'path' => 'classification', 'pattern' => '/^h/'],
            'valid_json', 'steps_count:1', 'beats_in_order:problem', 'subjects_per_step:2', 'single_cta_per_step',
            'no_unverified_figures', 'links_allowlisted', 'mentions_proof_point',
        ], $output, $case);
        $this->assertTrue($ok['passed'], json_encode($ok['results']));
        $this->assertSame(15, $ok['evaluated']);

        $mixed = $checker->check([
            ['type' => 'has_key', 'key' => 'subject'],
            ['type' => 'no_banned_phrases', 'phrases' => ['demos']],
            ['type' => 'min_length', 'path' => 'steps.0.body', 'min' => 500],
            'schema_valid',
            'respects_never_say',
            'nonsense',
        ], $output, $case);
        $this->assertFalse($mixed['passed']);
        $this->assertSame(['failed', 'failed', 'failed', 'skipped', 'skipped', 'failed'], array_column($mixed['results'], 'status'));
        $this->assertStringContainsString('missing key subject', $mixed['results'][0]['detail']);

        $withValidator = $checker->check(['schema_valid'], $output, $case, ['ok' => false, 'errors' => ['schema $.angle: missing']]);
        $this->assertSame('failed', $withValidator['results'][0]['status']);
        $this->assertStringContainsString('$.angle', $withValidator['results'][0]['detail']);
    }

    public function test_artisan_command_prints_the_table_and_coverage_before_correctness(): void
    {
        // Artisan::output() rather than expectsOutputToContain: that matcher
        // consumes one expectation per write, and a table row carries both the
        // case id and its judge count.
        $code = Artisan::call('skills:eval', ['slug' => self::SLUG, '--deterministic' => true, '--cases' => $this->synthetic]);
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('good_sequence', $out);
        $this->assertStringContainsString('Executed 2 of 3 declared · passed 1 · failed 1 · skipped 1 · unavailable 0', $out);
        $this->assertStringContainsString('+1 judge (not run)', $out);
        $this->assertStringNotContainsString('Pass rate', $out);

        $this->artisan('skills:eval', ['slug' => 'no-such-card'])
            ->expectsOutputToContain('No eval cases')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------------ //
    //  Required mode: success needs complete evidence, not zero failures
    // ------------------------------------------------------------------ //

    /**
     * The real corpus has no stored outputs, so every case skips. This exited
     * 0 until required mode existed — nine cases, nothing run, success.
     */
    public function test_an_all_skipped_required_suite_fails(): void
    {
        $this->artisan('skills:eval', ['slug' => self::SLUG])
            ->expectsOutputToContain('Executed 0 of 9 declared')
            ->expectsOutputToContain('Required suite incomplete: 9 of 9 case(s) did not execute')
            ->assertExitCode(1);
    }

    /**
     * Two ways to be empty. `cases: []` is refused by the loader. A list of
     * entries that are not cases loads as zero cases — `loadAll()` drops
     * non-array entries — and that reached the runner as declared 0, failed 0,
     * which exited 0 before required mode.
     */
    public function test_an_empty_required_suite_fails(): void
    {
        $this->artisan('skills:eval', ['slug' => self::SLUG, '--cases' => $this->casesFile([])])
            ->expectsOutputToContain('No `cases:` found')
            ->assertExitCode(1);

        $scalars = sys_get_temp_dir().'/sn-eval-'.uniqid().'.yaml';
        file_put_contents($scalars, "cases:\n  - not a case\n  - 42\n");
        $this->written[] = $scalars;

        $this->artisan('skills:eval', ['slug' => self::SLUG, '--cases' => $scalars])
            ->expectsOutputToContain('Required suite incomplete: no cases declared')
            ->assertExitCode(1);
    }

    public function test_one_skipped_case_among_passes_fails_the_required_suite(): void
    {
        $this->artisan('skills:eval', ['slug' => self::SLUG, '--cases' => $this->casesFile(['good_sequence', 'needs_live'])])
            ->expectsOutputToContain('Executed 1 of 2 declared · passed 1 · failed 0 · skipped 1')
            ->assertExitCode(1);
    }

    public function test_a_complete_valid_suite_passes(): void
    {
        $this->artisan('skills:eval', ['slug' => self::SLUG, '--cases' => $this->casesFile(['good_sequence'])])
            ->expectsOutputToContain('Executed 1 of 1 declared · passed 1 · failed 0 · skipped 0 · unavailable 0')
            ->doesntExpectOutputToContain('incomplete')
            ->assertExitCode(0);
    }

    /**
     * No schema verdict is not a pass. Before this, `$validatorOk !== null`
     * was one of two ways a case counted as evaluated, so a case whose
     * validator could not run still read `passed` whenever its properties did.
     */
    public function test_a_case_without_a_schema_verdict_is_unavailable_not_passed(): void
    {
        // `schema_valid` is removed on purpose. With it present, the property
        // itself reports VALIDATOR_UNAVAILABLE as a missing dependency and the
        // case goes unavailable by that route — which hid, on the first
        // sabotage run, that the case-level verdict check was untested. A case
        // that never asserts `schema_valid` (as the old blocked case did not)
        // has only the missing verdict to stop it passing.
        $file = $this->casesFile(['good_sequence'], static function (array $case): array {
            $case['expect']['properties'] = array_values(array_filter(
                $case['expect']['properties'],
                static fn (mixed $p): bool => $p !== 'schema_valid',
            ));

            return $case;
        });
        config(['agents.skills_root' => sys_get_temp_dir().'/sn-no-cards-'.uniqid()]);

        $report = app(EvalRunner::class)->run(self::SLUG, ['cases_file' => $file, 'write_report' => false]);

        $this->assertSame('unavailable', $report['cases'][0]['status']);
        $this->assertStringStartsWith('no schema verdict:', (string) $report['cases'][0]['note']);
        $this->assertSame(0, $report['summary']['passed']);
        $this->assertFalse($report['summary']['complete']);

        $this->artisan('skills:eval', ['slug' => self::SLUG, '--cases' => $file])
            ->expectsOutputToContain('unavailable 1')
            ->assertExitCode(1);
    }

    /**
     * A property that could not run for want of its input leaves the case
     * unavailable. `respects_never_say` over a fixture with no never-say rules
     * reports `skipped` — the property status is unchanged, and the case is
     * where the absence becomes blocking.
     */
    public function test_a_dependency_missing_property_makes_an_otherwise_passing_case_unavailable(): void
    {
        $file = $this->casesFile(['good_sequence'], static function (array $case): array {
            unset($case['fixture_brain']['people/user.md']);

            return $case;
        });

        $report = app(EvalRunner::class)->run(self::SLUG, ['cases_file' => $file, 'write_report' => false]);
        $case = $report['cases'][0];

        $this->assertTrue($case['validator']['ok'], 'the output itself is still valid');
        $this->assertSame([], array_filter($case['properties'], fn (array $p) => $p['status'] === 'failed'));
        $this->assertSame('unavailable', $case['status']);
        $this->assertSame('missing dependency: respects_never_say (NO_NEVER_SAY_RULES_AVAILABLE)', $case['note']);
    }

    public function test_exploratory_mode_marks_an_incomplete_run_and_still_fails_on_a_contradiction(): void
    {
        $this->artisan('skills:eval', ['slug' => self::SLUG, '--allow-incomplete' => true])
            ->expectsOutputToContain('INCOMPLETE — not a pass: 9 of 9 case(s) did not execute')
            ->assertExitCode(0);

        // Exploratory relaxes completeness only. A contradicted case is still a failure.
        $this->artisan('skills:eval', ['slug' => self::SLUG, '--allow-incomplete' => true, '--cases' => $this->synthetic])
            ->expectsOutputToContain('INCOMPLETE — not a pass')
            ->assertExitCode(1);
    }

    public function test_the_json_report_names_its_mode_policy_and_completeness(): void
    {
        $code = Artisan::call('skills:eval', ['slug' => self::SLUG, '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $code);
        $this->assertSame('required', $report['mode_policy']);
        $this->assertFalse($report['summary']['complete']);
        $this->assertSame(9, $report['summary']['skipped']);
    }

    /**
     * `subjects` is evidence coverage, not scoring weight: one property over
     * three subjects contributes one outcome. If it counted three, an output
     * growing from three items to thirty would silently change the denominator.
     */
    public function test_a_property_over_many_subjects_contributes_one_outcome(): void
    {
        $result = (new PropertyChecker)->check(
            [['type' => 'not_empty', 'path' => 'items[].body']],
            ['items' => [['body' => 'a'], ['body' => 'b'], ['body' => 'c']]],
        );

        $this->assertSame(1, $result['evaluated']);
        $this->assertSame(3, $result['results'][0]['subjects']);
    }

    /**
     * A cases file holding only the named synthetic cases, optionally
     * transformed. Anchors resolve on parse, so each case carries its full
     * fixture brain.
     *
     * @param  list<string>  $ids
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $transform
     */
    private function casesFile(array $ids, ?callable $transform = null): string
    {
        $doc = Yaml::parse(self::syntheticCases());
        $doc['cases'] = array_values(array_map(
            static fn (array $case): array => $transform === null ? $case : $transform($case),
            array_filter($doc['cases'], static fn (array $case): bool => in_array($case['id'], $ids, true)),
        ));
        unset($doc['fixtures']);

        $path = sys_get_temp_dir().'/sn-eval-'.uniqid().'.yaml';
        file_put_contents($path, Yaml::dump($doc, 12, 2));
        $this->written[] = $path;

        return $path;
    }
}
