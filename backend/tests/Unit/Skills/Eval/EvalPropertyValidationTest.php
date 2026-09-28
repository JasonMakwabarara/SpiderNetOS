<?php

declare(strict_types=1);

namespace Tests\Unit\Skills\Eval;

use App\Services\Skills\SkillRegistry;
use Tests\TestCase;

/**
 * `skills:validate` now reads the property registry, so a case cannot name a
 * check that nothing declares, or hand a declared check an argument it cannot
 * use.
 *
 * Before this, the eval block was checked for structure — the file parses, each
 * case has id/inputs/expect — and never for contents. A case could name
 * fifty-one property types that no code implements and validate cleanly, which
 * is how they got there.
 *
 * The registry is copied to a temp root and doctored rather than mocked,
 * because the thing worth proving is that the real nine cards pass and a
 * damaged one does not.
 */
class EvalPropertyValidationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/skills-'.bin2hex(random_bytes(6));
        self::copyTree((string) config('agents.skills_root'), $this->root);
        SkillRegistry::flush();
    }

    protected function tearDown(): void
    {
        self::deleteTree($this->root);
        SkillRegistry::flush();

        parent::tearDown();
    }

    public function test_the_shipped_corpus_names_no_undeclared_property(): void
    {
        $errors = [];
        foreach ((new SkillRegistry($this->root))->validateAll() as $slug => $slugErrors) {
            foreach ($slugErrors as $error) {
                if (str_contains($error, 'property type') || str_contains($error, 'expected')) {
                    $errors[] = "{$slug}: {$error}";
                }
            }
        }

        $this->assertSame([], $errors);
    }

    public function test_a_typo_in_a_property_name_is_an_error(): void
    {
        $this->doctor('slots_count:2', 'slots_kount:2');

        $this->assertStringContainsString(
            'unknown property type "slots_kount"',
            implode("\n", $this->errorsFor('meeting-booking')),
        );
    }

    public function test_an_argument_the_type_cannot_use_is_an_error(): void
    {
        $this->doctor('slots_count:2', 'slots_count:two');

        $this->assertStringContainsString(
            'expected a number or "item_id=<number>", got "two"',
            implode("\n", $this->errorsFor('meeting-booking')),
        );
    }

    /**
     * A colon followed by a space makes a YAML mapping, not a property string.
     * `- steps_count: 3` parses to `{steps_count: 3}`, arrives with no `type`,
     * and used to fail deep inside the checker as `unknown property type ""`.
     */
    public function test_a_colon_space_property_is_caught_as_a_yaml_mistake(): void
    {
        $this->doctor('- slots_count:2', '- slots_count: 2');

        $this->assertStringContainsString(
            'a colon followed by a space makes it a YAML mapping',
            implode("\n", $this->errorsFor('meeting-booking')),
        );
    }

    /** A declared-but-unimplemented type is not an error yet — it is owned debt. */
    public function test_a_planned_type_is_not_yet_a_validate_error(): void
    {
        $errors = implode("\n", $this->errorsFor('meeting-booking'));

        $this->assertStringNotContainsString('slots_within_hours', $errors);
        $this->assertStringNotContainsString('no handler yet', $errors);
    }

    // ---------------------------------------------------------------- //
    //  An assertion satisfied by an empty collection must be paired
    // ---------------------------------------------------------------- //

    /**
     * The shipped corpus is clean, and it is clean for the right reason.
     *
     * The first version of this check flagged five cases and would have forced
     * five redundant count assertions into the corpus — `angles`, `sources` and
     * `blocks` all declare `minItems >= 1`, so restating that in a case is the
     * duplication §1.4b #5 says to delete rather than add.
     */
    public function test_the_shipped_corpus_has_no_unpaired_empty_tolerant_assertion(): void
    {
        $errors = [];
        foreach ((new SkillRegistry($this->root))->validateAll() as $slug => $slugErrors) {
            foreach ($slugErrors as $error) {
                if (str_contains($error, 'satisfied by an empty') || str_contains($error, 'admits zero')) {
                    $errors[] = "{$slug}: {$error}";
                }
            }
        }

        $this->assertSame([], $errors);
    }

    /**
     * The schema only settles emptiness for a case that actually checks it.
     * Drop `schema_valid` and the guarantee stops applying to that case.
     */
    public function test_a_schema_minimum_counts_only_where_the_case_asserts_schema_valid(): void
    {
        $this->doctor(
            "        - schema_valid\n        - confidence_lte:0.4",
            '        - confidence_lte:0.4',
            'prospect-research-analysis/evals/cases.yaml',
        );

        $this->assertStringContainsString(
            '"every_angle_has_source" is satisfied by an empty angles[]',
            implode('
', $this->errorsFor('prospect-research-analysis')),
        );
    }

    /** Remove the schema's guarantee and the same case needs an explicit count. */
    public function test_a_collection_the_schema_no_longer_guarantees_needs_a_count(): void
    {
        $this->doctor("minItems: 1\n          maxItems: 3", "minItems: 0\n          maxItems: 3", 'prospect-research-analysis/card.yaml');

        $this->assertStringContainsString(
            'is satisfied by an empty angles[]',
            implode('
', $this->errorsFor('prospect-research-analysis')),
        );
    }

    /**
     * A companion whose bound still admits zero is not a pairing. This is the
     * difference between `angles_min:1`, which excludes the empty case, and
     * `angles_min:0`, which merely tolerates it.
     */
    public function test_a_companion_count_whose_bound_admits_zero_is_refused(): void
    {
        $this->doctor(
            "        - schema_valid\n        - confidence_lte:0.4",
            "        - confidence_lte:0.4\n        - angles_min:0",
            'prospect-research-analysis/evals/cases.yaml',
        );

        $this->assertStringContainsString(
            'whose bound still admits zero',
            implode('
', $this->errorsFor('prospect-research-analysis')),
        );
    }

    /** @return list<string> */
    private function errorsFor(string $slug): array
    {
        SkillRegistry::flush();

        return (new SkillRegistry($this->root))->validateAll()[$slug] ?? [];
    }

    private function doctor(string $from, string $to, string $relative = 'meeting-booking/evals/cases.yaml'): void
    {
        $file = $this->root.'/'.$relative;
        $text = (string) file_get_contents($file);
        $at = strpos($text, $from);
        $this->assertNotFalse($at, 'the fixture this test doctors has moved');
        // The first occurrence only. `slots_count:2` legitimately appears three
        // times in meeting-booking, and replacing all of them would make the
        // test read as narrower than it is.
        file_put_contents($file, substr_replace($text, $to, $at, strlen($from)));
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $from.'/'.$entry;
            is_dir($src) ? self::copyTree($src, $to.'/'.$entry) : copy($src, $to.'/'.$entry);
        }
    }

    private static function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) ? self::deleteTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
