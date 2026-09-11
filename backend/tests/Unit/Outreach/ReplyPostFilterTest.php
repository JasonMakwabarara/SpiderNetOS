<?php

declare(strict_types=1);

namespace Tests\Unit\Outreach;

use App\Services\Outreach\Bot\ReplyPostFilter;
use PHPUnit\Framework\TestCase;

class ReplyPostFilterTest extends TestCase
{
    private ReplyPostFilter $filter;

    private array $facts = [
        'commission_pct' => 30, 'months' => 12, 'cookie_days' => 30, 'min_payout_usd' => 50, 'hold_days' => 30,
        'terms_url' => 'https://hannah-ai.world/affiliate-terms', 'join_url' => 'https://hannah.affonso.io/?group=grp1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new ReplyPostFilter;
    }

    private function json(array $obj): string
    {
        return (string) json_encode($obj);
    }

    public function test_valid_json_in_a_code_fence_passes_with_normalised_fields(): void
    {
        $raw = "```json\n".$this->json([
            'reply' => 'Thanks Mike! Commission is 30% recurring for 12 months. Join here: https://hannah.affonso.io/?group=grp1',
            'action' => 'None', 'extracted' => ['email' => 'null', 'country' => ' US ', 'handle' => ''], 'confidence' => 1.7,
        ])."\n```";

        $r = $this->filter->check($raw, $this->facts);

        $this->assertTrue($r['ok']);
        $this->assertNull($r['reason']);
        $this->assertSame('none', $r['action']);
        $this->assertSame(['email' => null, 'country' => 'US', 'handle' => null], $r['extracted']);
        $this->assertSame(1.0, $r['confidence']);
    }

    public function test_garbage_and_unknown_actions_are_refused(): void
    {
        $this->assertSame('invalid_json', $this->filter->check('Sure! Here is my reply: hello', $this->facts)['reason']);
        $this->assertSame('unknown_action', $this->filter->check($this->json(['reply' => 'Hi', 'action' => 'send_money']), $this->facts)['reason']);
        $this->assertSame('empty_reply', $this->filter->check($this->json(['reply' => '  ', 'action' => 'none']), $this->facts)['reason']);
        $this->assertSame('too_long', $this->filter->check($this->json(['reply' => str_repeat('a', 1201), 'action' => 'none']), $this->facts)['reason']);
    }

    public function test_model_requested_handoff_passes_even_without_a_reply(): void
    {
        $r = $this->filter->check($this->json(['reply' => '', 'action' => 'handoff', 'confidence' => 0.9]), $this->facts);

        $this->assertTrue($r['ok']);
        $this->assertSame('handoff', $r['action']);
    }

    public function test_banned_promises_and_foreign_links_are_refused(): void
    {
        $this->assertSame('banned_phrase', $this->filter->check($this->json(['reply' => 'Guaranteed income every month!', 'action' => 'none']), $this->facts)['reason']);
        $this->assertSame('banned_phrase', $this->filter->check($this->json(['reply' => 'We can do a custom commission for you.', 'action' => 'none']), $this->facts)['reason']);
        $this->assertSame('link_not_allowed', $this->filter->check($this->json(['reply' => 'See https://bit.ly/abc for details', 'action' => 'none']), $this->facts)['reason']);

        $ok = $this->filter->check($this->json(['reply' => 'Terms: https://www.hannah-ai.world/affiliate-terms. Join: https://hannah.affonso.io/?group=grp1.', 'action' => 'none']), $this->facts);
        $this->assertTrue($ok['ok']);
    }

    public function test_every_figure_must_come_from_the_facts(): void
    {
        $bad = ['Commission is 50% for life.', 'You get paid at $100.', 'Cookies last 90 days.', 'Payouts take 2 weeks.', 'Earn 30 % now with 20% bonus.'];
        foreach ($bad as $reply) {
            $this->assertSame('unverified_figure', $this->filter->check($this->json(['reply' => $reply, 'action' => 'none']), $this->facts)['reason'], $reply);
        }

        $good = ['30% of net payments, recurring for 12 months, with a 30-day cookie; payouts from $50 after a 30 day hold.', 'Payouts are monthly once you reach 50 USD.'];
        foreach ($good as $reply) {
            $this->assertTrue($this->filter->check($this->json(['reply' => $reply, 'action' => 'none']), $this->facts)['ok'], $reply);
        }
    }

    public function test_failed_checks_keep_the_raw_text_for_the_handoff_card(): void
    {
        $r = $this->filter->check($this->json(['reply' => 'Commission is 50%', 'action' => 'signed_up']), $this->facts);

        $this->assertFalse($r['ok']);
        $this->assertSame('signed_up', $r['action']);
        $this->assertStringContainsString('50%', $r['raw']);
    }
}
