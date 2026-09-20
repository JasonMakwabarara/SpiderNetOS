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

    /** @return list<string> */
    private function errorsFor(string $slug): array
    {
        SkillRegistry::flush();

        return (new SkillRegistry($this->root))->validateAll()[$slug] ?? [];
    }

    private function doctor(string $from, string $to): void
    {
        $file = $this->root.'/meeting-booking/evals/cases.yaml';
        $text = (string) file_get_contents($file);
        $this->assertStringContainsString($from, $text, 'the fixture this test doctors has moved');
        file_put_contents($file, str_replace($from, $to, $text));
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
