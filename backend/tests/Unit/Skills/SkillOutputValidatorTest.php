<?php

declare(strict_types=1);

namespace Tests\Unit\Skills;

use App\Services\Skills\SkillCard;
use App\Services\Skills\SkillOutputValidator;
use App\Services\Skills\SkillRegistry;
use Tests\TestCase;

/**
 * ReplyPostFilter generalised: JSON extraction + one repair pass, the card's
 * output schema (step counts are minItems/maxItems), banned phrases, links
 * off the FACTS allowlist and figures the FACTS do not contain.
 */
class SkillOutputValidatorTest extends TestCase
{
    private const FACTS = [
        'pricing' => 'From R1,800 per month, no setup fee',
        'proof_points' => ['Café Roux cut month-end from 9 days to 2 (2025)', '41 cafés on the books, none lost in 18 months'],
        'links' => ['https://northbeam.example/cafes', 'https://cal.example/northbeam/15'],
        'products' => ['Monthly bookkeeping'],
        'affiliate' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SkillRegistry::flush();
    }

    private function card(): SkillCard
    {
        return (new SkillRegistry())->get('cold-email-drafting');
    }

    private function validator(): SkillOutputValidator
    {
        return new SkillOutputValidator(new SkillRegistry());
    }

    /** @return array<string, mixed> a schema-valid draft_sequence */
    private function payload(array $overrides = []): array
    {
        $step = fn (int $n, string $beat, int $day) => [
            'step' => $n,
            'beat' => $beat,
            'subjects' => ["Your month-end, closed by the 5th ({$n})", "Books off your desk by Tuesday ({$n})"],
            'body' => "Morning — one specific idea for you, thirty seconds to read. Most café owners we meet still do month-end at the kitchen table. We close your books by the 5th, every month. Café Roux cut month-end from 9 days to 2. Worth a 15-minute look? https://cal.example/northbeam/15",
            'send_day' => $day,
            'cta' => 'Worth a 15-minute look?',
        ];

        return array_replace([
            'campaign' => 'cafes-q4',
            'segment' => 'Cape Town cafés',
            'angle' => 'Owners still do month-end themselves; we take it off the desk by the 5th.',
            'steps' => [$step(1, 'problem', 0), $step(2, 'proof', 3), $step(3, 'close', 7)],
        ], $overrides);
    }

    public function test_valid_output_passes(): void
    {
        $result = $this->validator()->validate($this->card(), json_encode($this->payload()), self::FACTS);

        $this->assertTrue($result->ok, json_encode($result->errors));
        $this->assertFalse($result->repaired);
        $this->assertSame('cafes-q4', $result->data['campaign']);
        $this->assertSame([], $result->errors);
    }

    public function test_invalid_json_is_repaired_once(): void
    {
        $json = json_encode($this->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // A code fence, a trailing comma and smart quotes around a key — the usual model slips.
        $broken = "Here is the sequence:\n```json\n".preg_replace('/"campaign"/', "\u{201C}campaign\u{201D}", preg_replace('/"cta": "([^"]+)"\n/', "\"cta\": \"$1\",\n", $json))."\n```";

        $result = $this->validator()->validate($this->card(), $broken, self::FACTS);

        $this->assertTrue($result->ok, json_encode($result->errors));
        $this->assertTrue($result->repaired);
        $this->assertSame('cafes-q4', $result->data['campaign']);
    }

    public function test_garbage_is_invalid_json(): void
    {
        $result = $this->validator()->validate($this->card(), 'Sorry, I cannot help with that.', self::FACTS);

        $this->assertFalse($result->ok);
        $this->assertSame(['invalid_json'], $result->codes());
        $this->assertNull($result->data);

        $empty = $this->validator()->validate($this->card(), '   ', self::FACTS);
        $this->assertSame(['empty'], $empty->codes());
    }

    public function test_wrong_step_count_is_a_schema_error(): void
    {
        $payload = $this->payload();
        array_pop($payload['steps']);

        $result = $this->validator()->validate($this->card(), json_encode($payload), self::FACTS);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->has('schema'));
        $stepError = array_values(array_filter($result->errors, fn (array $e) => str_contains($e['path'] ?? '', 'steps')));
        $this->assertNotEmpty($stepError);
        $this->assertStringContainsString('at least 3 items', $stepError[0]['message']);
        $this->assertNotNull($result->data, 'the parsed data is still returned for debugging');
    }

    public function test_schema_enforces_required_keys_enums_and_max_length(): void
    {
        $payload = $this->payload();
        unset($payload['angle']);
        $payload['steps'][1]['beat'] = 'pitch';
        $payload['steps'][2]['subjects'][0] = str_repeat('x', 81);

        $result = $this->validator()->validate($this->card(), json_encode($payload), self::FACTS);

        $messages = implode("\n", array_map(fn (array $e) => ($e['path'] ?? '').': '.$e['message'], $result->errors));
        $this->assertStringContainsString('missing required key "angle"', $messages);
        $this->assertStringContainsString('$.steps[1].beat: must be one of', $messages);
        $this->assertStringContainsString('$.steps[2].subjects[0]: must be at most 80 characters', $messages);
    }

    public function test_invented_percentage_is_an_unverified_figure(): void
    {
        $payload = $this->payload();
        $payload['steps'][1]['body'] = 'Café Roux cut month-end from 9 days to 2 and saved 37% on admin. Worth a look? https://cal.example/northbeam/15';

        $result = $this->validator()->validate($this->card(), json_encode($payload), self::FACTS);

        $this->assertFalse($result->ok);
        $this->assertContains('unverified_figure', $result->codes());
        $figure = array_values(array_filter($result->errors, fn (array $e) => $e['code'] === 'unverified_figure'))[0];
        $this->assertSame('$.steps[1].body', $figure['path']);
        $this->assertStringContainsString('37%', $figure['message']);
    }

    public function test_currency_amounts_must_be_in_facts(): void
    {
        $ok = $this->payload();
        $ok['steps'][0]['body'] = 'From R1,800 per month, no setup fee — and the books closed by the 5th. Worth a 15-minute look?';
        $this->assertTrue($this->validator()->validate($this->card(), json_encode($ok), self::FACTS)->ok);

        $bad = $this->payload();
        $bad['steps'][0]['body'] = 'Only R1,200 per month for the first six months — the books closed by the 5th. Worth a 15-minute look?';
        $result = $this->validator()->validate($this->card(), json_encode($bad), self::FACTS);
        $this->assertContains('unverified_figure', $result->codes());
    }

    public function test_link_off_the_allowlist_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['steps'][2]['body'] = 'Last one from me. If it is ever useful: https://instagram.com/northbeam — otherwise, all the best with month-end.';

        $result = $this->validator()->validate($this->card(), json_encode($payload), self::FACTS);

        $this->assertFalse($result->ok);
        $this->assertContains('link_not_allowed', $result->codes());
        $this->assertStringContainsString('instagram.com', array_values(array_filter($result->errors, fn (array $e) => $e['code'] === 'link_not_allowed'))[0]['message']);

        // No links in FACTS at all → every URL is off the allowlist.
        $none = $this->validator()->validate($this->card(), json_encode($this->payload()), ['links' => []]);
        $this->assertContains('link_not_allowed', $none->codes());
    }

    public function test_banned_phrase_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['steps'][1]['body'] = 'Guaranteed results inside a month, or that month is free. Worth a 15-minute look? https://cal.example/northbeam/15';

        $result = $this->validator()->validate($this->card(), json_encode($payload), self::FACTS);

        $this->assertFalse($result->ok);
        $this->assertContains('banned_phrase', $result->codes());

        // Tenant-specific "never say" phrases ride along in $facts.
        $discount = $this->payload();
        $discount['steps'][0]['body'] = 'We would love to offer a discount on your first month. Worth a 15-minute look?';
        $result = $this->validator()->validate($this->card(), json_encode($discount), self::FACTS + ['never_say' => ['discount']]);
        $this->assertContains('banned_phrase', $result->codes());
    }

    public function test_next_steps_must_name_known_or_planned_skills(): void
    {
        $payload = $this->payload(['next_steps' => [
            ['id' => 'x', 'label' => 'Do a thing', 'does' => 'Runs nothing real', 'skill' => 'skill-that-does-not-exist'],
            ['id' => 'y', 'label' => 'Follow up', 'does' => 'Drafts the day-3 follow-up', 'skill' => 'follow-up-drafting'],
        ]]);

        $result = $this->validator()->validate($this->card(), json_encode($payload), self::FACTS);

        $this->assertFalse($result->ok);
        $paths = array_map(fn (array $e) => $e['path'] ?? '', $result->errors);
        $this->assertContains('$.next_steps[0].skill', $paths);
        $this->assertNotContains('$.next_steps[1].skill', $paths);
    }

    public function test_accepts_a_slug_or_a_skills_row_as_the_card(): void
    {
        $result = $this->validator()->validate('cold-email-drafting', json_encode($this->payload()), self::FACTS);
        $this->assertTrue($result->ok, json_encode($result->errors));

        $this->expectException(\InvalidArgumentException::class);
        $this->validator()->validate('no-such-skill', '{}', self::FACTS);
    }

    public function test_validation_result_shape(): void
    {
        $result = $this->validator()->validate($this->card(), json_encode($this->payload()), self::FACTS);

        $this->assertSame(['ok', 'data', 'errors', 'repaired'], array_keys($result->toArray()));
    }
}
