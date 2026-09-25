<?php

declare(strict_types=1);

namespace Tests\Unit\Revisions;

use App\Services\Revisions\RevisionRecorder;
use PHPUnit\Framework\TestCase;

/**
 * Pure edit-ledger maths (plan D8 #1): normalised distance, deterministic
 * categories and the clean threshold. No database.
 */
class RevisionRecorderTest extends TestCase
{
    private RevisionRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new RevisionRecorder;
    }

    public function test_identical_and_whitespace_only_changes_have_zero_distance(): void
    {
        $body = "Hi Sam,\n\nWe help agencies book 10 more calls a month.\n\nWorth a chat?";

        $this->assertSame(0.0, $this->recorder->distance($body, $body));
        $this->assertSame(0.0, $this->recorder->distance($body, "Hi Sam,\r\n\r\nWe help agencies   book 10 more calls a month.  \n\nWorth a chat?  "));
        $this->assertSame([], $this->recorder->categories($body, $body));
    }

    public function test_small_edit_is_clean_and_rewrite_is_not(): void
    {
        $original = 'Hi Sam, we help agencies book ten more qualified calls a month without hiring an SDR. Worth a quick chat next week?';
        $typoFix = 'Hi Sam, we help agencies book ten more qualified calls a month without hiring an SDR. Worth a quick chat next week!';
        $rewrite = 'Sam — quick one. Agencies like yours add ten qualified calls a month with us, no SDR hire. Open to a chat?';

        $small = $this->recorder->distance($original, $typoFix);
        $large = $this->recorder->distance($original, $rewrite);

        $this->assertLessThan(RevisionRecorder::CLEAN_THRESHOLD, $small);
        $this->assertTrue($this->recorder->isClean($small));
        $this->assertGreaterThan(0.3, $large);
        $this->assertFalse($this->recorder->isClean($large));
        $this->assertSame(1.0, $this->recorder->distance($original, ''));
        $this->assertFalse($this->recorder->isClean(0.05));
        $this->assertTrue($this->recorder->isClean(0.0499));
    }

    public function test_long_bodies_fall_back_to_sentence_lists_and_stay_bounded(): void
    {
        $sentence = 'We help agencies book more qualified calls every month without adding headcount. ';
        $original = str_repeat($sentence, 60); // ~5k chars → sentence-level path
        $edited = str_repeat($sentence, 58).'We also handle the follow-ups. ';

        $d = $this->recorder->distance($original, $edited);

        $this->assertGreaterThan(0.0, $d);
        $this->assertLessThan(0.15, $d);
        $this->assertLessThanOrEqual(1.0, $this->recorder->distance($original, str_repeat('Completely different text here. ', 70)));
    }

    public function test_link_and_number_edits_are_categorised(): void
    {
        $original = 'Book here: https://cal.example.com/sam — our clients see 20% more replies.';
        $links = 'Book here: https://cal.example.com/team — our clients see 20% more replies.';
        $numbers = 'Book here: https://cal.example.com/sam — our clients see 35% more replies.';

        $this->assertContains('links', $this->recorder->categories($original, $links));
        $this->assertNotContains('numbers', $this->recorder->categories($original, $links));

        $this->assertContains('numbers', $this->recorder->categories($original, $numbers));
        $this->assertNotContains('links', $this->recorder->categories($original, $numbers));
    }

    public function test_length_ask_tone_and_facts_are_categorised(): void
    {
        $original = 'Hi Sam, we help agencies book more calls. Our client Acme doubled demos last quarter. Would you be open to a 15-minute chat?';

        $shorter = 'Hi Sam, we help agencies book more calls.';
        $this->assertContains('length', $this->recorder->categories($original, $shorter));

        $noAsk = 'Hi Sam, we help agencies book more calls. Our client Acme doubled demos last quarter. Have a great week.';
        $this->assertContains('ask', $this->recorder->categories($original, $noAsk));

        $hype = 'Hi Sam, we help agencies book more calls!!! Our amazing client Acme doubled demos last quarter. Would you be open to a 15-minute chat?';
        $this->assertContains('tone', $this->recorder->categories($original, $hype));

        $facts = 'Hi Sam, we help agencies book more calls. Our client Globex doubled demos last quarter. Would you be open to a 15-minute chat?';
        $categories = $this->recorder->categories($original, $facts);
        $this->assertContains('facts', $categories);
        $this->assertNotContains('length', $categories);
        $this->assertNotContains('ask', $categories);
    }

    public function test_categories_follow_a_fixed_order(): void
    {
        $original = 'Hi Sam, see https://a.example.com — 10 clients, Acme included. Interested?';
        $edited = 'Hey Sam!! see https://b.example.com — 12 clients, Globex included. Anyway, that is all for now, no need to reply, just sharing what we do these days with everyone we know.';

        $categories = $this->recorder->categories($original, $edited);

        $this->assertSame(array_values(array_intersect(['facts', 'links', 'numbers', 'length', 'tone', 'ask'], $categories)), $categories);
        $this->assertContains('facts', $categories);
        $this->assertContains('links', $categories);
        $this->assertContains('numbers', $categories);
        $this->assertContains('length', $categories);
        $this->assertContains('tone', $categories);
    }
}
