<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Models\ConversationMessage;
use App\Models\Event;
use App\Models\PartnerProspect;
use App\Services\FeatureFlag;
use App\Services\Outreach\Ops\OutreachDigest;
use App\Services\Outreach\Ops\OutreachHealth;
use App\Services\Outreach\OutreachSender;
use App\Services\Outreach\OutreachSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Pre-flight, digest and the bounce auto-pause: the ops layer an operator
 * drives the rollout with.
 */
class OutreachOpsTest extends OutreachTestCase
{
    /** @var array<string, string> */
    private array $redis = [];

    protected function setUp(): void
    {
        parent::setUp();

        // FeatureFlag overrides live in Redis; stand in with an array so
        // outreach:enable can actually flip a flag under test.
        Redis::shouldReceive('set')->andReturnUsing(function (string $key, $value) {
            $this->redis[$key] = (string) $value;

            return true;
        });
        Redis::shouldReceive('get')->andReturnUsing(fn (string $key) => $this->redis[$key] ?? null);
        Redis::shouldReceive('del')->andReturnUsing(function (string $key) {
            unset($this->redis[$key]);

            return 1;
        });
    }

    private function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    /** Import and invite N creators, then mark the first $bounced of them bounced. */
    private function sendWave(int $count, int $bounced = 0): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['name' => "Creator {$i}", 'domain' => "https://www.tiktok.com/@creator{$i}", 'primary' => 'x', 'email' => "creator{$i}@example.test"];
        }
        $this->import($rows);
        app(OutreachSettings::class)->update($this->tenant, [
            'sending' => ['per_run_cap' => max(10, $count), 'warmup' => [['from_day' => 1, 'cap' => max(10, $count)]]],
        ]);
        app(OutreachSender::class)->runForTenant($this->tenant->refresh(), false, true);

        if ($bounced > 0) {
            $ids = PartnerProspect::forTenant($this->tenant->id)->orderBy('handle')->limit($bounced)->pluck('id');
            PartnerProspect::whereIn('id', $ids)->update(['status' => PartnerProspect::STATUS_BOUNCED, 'bounced_at' => now()]);
        }
    }

    public function test_health_blocks_every_stage_until_the_connectors_and_facts_are_there(): void
    {
        $report = app(OutreachHealth::class)->report($this->tenant);

        $this->assertSame(['id', 'slug', 'name', 'automation_level', 'reply_mode'], array_keys($report['tenant']));
        $this->assertFalse($report['ready']['send']);
        $this->assertFalse($report['ready']['bot']);
        $this->assertFalse($report['ready']['affiliate']);
        $this->assertContains('mailbox_smtp', $report['blocking']['send']);
        $this->assertContains('mailbox_imap', $report['blocking']['bot']);
        $this->assertContains('affonso_api', $report['blocking']['affiliate']);

        // postal_address is a send-stage blocker; the base fixture sets it, join_url too.
        $this->assertNotContains('postal_address', $report['blocking']['send']);
        $this->assertNotContains('join_url', $report['blocking']['send']);

        // Every failing check carries the fix, and passing ones carry none.
        foreach ($report['checks'] as $check) {
            $this->assertSame($check['status'] === 'ok', $check['fix'] === null, $check['key']);
        }
    }

    public function test_health_clears_the_send_stage_once_the_mailbox_is_connected(): void
    {
        $this->connectMailbox(withImap: true);
        $this->connectAffonso();

        $report = app(OutreachHealth::class)->report($this->tenant->refresh());

        $this->assertSame([], $report['blocking']['send']);
        $this->assertSame([], $report['blocking']['bot']);
        $this->assertSame([], $report['blocking']['affiliate']);
        $this->assertTrue($report['ready']['send']);
    }

    public function test_bounce_rate_counts_prospects_not_messages(): void
    {
        $this->connectMailbox();
        $this->sendWave(4, bounced: 1);
        $health = app(OutreachHealth::class);

        $rate = $health->bounceRate((string) $this->tenant->id);
        $this->assertSame(4, $rate['sent']);
        $this->assertSame(1, $rate['bounced']);
        $this->assertSame(0.25, $rate['rate']);

        // A second step to the same dead address must not count twice.
        $bounced = PartnerProspect::forTenant($this->tenant->id)->whereNotNull('bounced_at')->firstOrFail();
        $conversation = DB::table('conversations')->where('lead_id', $bounced->lead_id)->value('id');
        ConversationMessage::create([
            'tenant_id' => $this->tenant->id, 'conversation_id' => $conversation, 'direction' => 'out',
            'body' => 'nudge', 'status' => 'sent', 'template_key' => 'partner.nudge', 'sent_by' => 'system',
        ]);

        $again = $health->bounceRate((string) $this->tenant->id);
        $this->assertSame(4, $again['sent']);
        $this->assertSame(1, $again['bounced']);
    }

    public function test_digest_is_flag_gated_and_reports_the_day(): void
    {
        $this->connectMailbox();
        $this->sendWave(3);
        $digests = app(OutreachDigest::class);

        $this->assertSame('flag_off', $digests->run($this->tenant)['skipped']);

        $this->flags(['outreach.digest' => 'on']);
        $result = $digests->run($this->tenant->refresh());

        $this->assertNull($result['skipped']);
        $this->assertFalse($result['paused']);
        $this->assertStringContainsString('Sent 3', $result['lines'][0]);
        $this->assertSame(1, $result['notified']);
        $this->assertTrue(Event::where('tenant_id', $this->tenant->id)->where('event_type', 'outreach.digest.sent')->exists());
    }

    public function test_a_dry_run_digest_notifies_nobody(): void
    {
        $this->connectMailbox();
        $this->sendWave(2);
        $this->flags(['outreach.digest' => 'on']);

        $result = app(OutreachDigest::class)->run($this->tenant->refresh(), dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['notified']);
        $this->assertFalse(Event::where('tenant_id', $this->tenant->id)->where('event_type', 'outreach.digest.sent')->exists());
    }

    public function test_a_small_sample_never_triggers_the_auto_pause(): void
    {
        $this->connectMailbox();
        $this->flags(['outreach.digest' => 'on', 'outreach.sending' => 'on']);
        $this->sendWave(4, bounced: 2); // 50%, but only 4 sends

        $stats = app(OutreachHealth::class)->stats((string) $this->tenant->id);
        $this->assertNull(app(OutreachDigest::class)->shouldPause((string) $this->tenant->id, $stats));
    }

    public function test_too_many_bounces_pauses_sending_and_raises_an_escalation(): void
    {
        $this->connectMailbox();
        $this->flags(['outreach.digest' => 'on', 'outreach.sending' => 'on']);
        $this->sendWave(24, bounced: 3); // 12.5% over 24 sends

        $result = app(OutreachDigest::class)->run($this->tenant->refresh());

        $this->assertTrue($result['paused']);
        $this->assertStringContainsString('bounce rate 12.5%', (string) $result['pause_reason']);
        $this->assertFalse(FeatureFlag::on('outreach.sending', (string) $this->tenant->id));

        $approval = DB::table('approvals')->where('tenant_id', $this->tenant->id)
            ->where('resource_type', 'outreach_sending')->first();
        $this->assertNotNull($approval);
        $this->assertSame('escalation', $approval->approval_type);
        $context = (array) json_decode((string) $approval->context, true);
        $this->assertSame('high', $context['risk']);
        $this->assertStringContainsString('outreach:enable send', $context['resume']);

        $this->assertTrue(Event::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'outreach.sending.auto_paused')->exists());

        // Already paused: the next digest does not pause (or escalate) again.
        $second = app(OutreachDigest::class)->run($this->tenant->refresh());
        $this->assertFalse($second['paused']);
        $this->assertSame(1, DB::table('approvals')->where('tenant_id', $this->tenant->id)
            ->where('resource_type', 'outreach_sending')->count());
    }

    public function test_the_doctor_command_prints_blockers_and_the_enable_command_refuses_them(): void
    {
        $this->artisan('outreach:doctor', ['tenant' => $this->tenant->slug])
            ->expectsOutputToContain('mailbox_smtp')
            ->assertExitCode(0);

        $this->artisan('outreach:enable', ['stage' => 'send', '--tenant' => $this->tenant->slug])
            ->expectsOutputToContain("Not ready for 'send'")
            ->assertExitCode(1);
        $this->assertFalse(FeatureFlag::on('outreach.sending', (string) $this->tenant->id));
    }

    public function test_enabling_send_starts_the_warmup_clock_once_and_rolls_back(): void
    {
        $this->connectMailbox(withImap: true);

        $this->artisan('outreach:enable', ['stage' => 'send', '--tenant' => $this->tenant->slug])->assertExitCode(0);

        $settings = app(OutreachSettings::class);
        $startedAt = ((array) $settings->for($this->tenant->refresh())['sending'])['started_at'];
        $this->assertNotNull($startedAt);
        $this->assertTrue(FeatureFlag::on('outreach.sending', (string) $this->tenant->id));
        $this->assertTrue(FeatureFlag::on('outreach.inbound_poll', (string) $this->tenant->id));

        // Re-enabling must not restart the warm-up ladder.
        $this->artisan('outreach:enable', ['stage' => 'send', '--tenant' => $this->tenant->slug])->assertExitCode(0);
        $this->assertSame($startedAt, ((array) $settings->for($this->tenant->refresh())['sending'])['started_at']);

        $this->artisan('outreach:enable', ['stage' => 'send', '--tenant' => $this->tenant->slug, '--off' => true])->assertExitCode(0);
        $this->assertFalse(FeatureFlag::on('outreach.sending', (string) $this->tenant->id));
    }

    public function test_auto_mode_needs_a_clean_approval_record(): void
    {
        $this->connectMailbox(withImap: true);
        $settings = app(OutreachSettings::class);

        $this->artisan('outreach:enable', ['stage' => 'auto', '--tenant' => $this->tenant->slug])
            ->expectsOutputToContain('0 approved draft(s)')
            ->assertExitCode(1);
        $this->assertSame('approve', ((array) $settings->for($this->tenant->refresh())['replies'])['mode']);

        $this->artisan('outreach:enable', ['stage' => 'auto', '--tenant' => $this->tenant->slug, '--force' => true])
            ->assertExitCode(0);
        $this->assertSame('auto', ((array) $settings->for($this->tenant->refresh())['replies'])['mode']);

        $this->artisan('outreach:enable', ['stage' => 'auto', '--tenant' => $this->tenant->slug, '--off' => true])
            ->assertExitCode(0);
        $this->assertSame('approve', ((array) $settings->for($this->tenant->refresh())['replies'])['mode']);
    }
}
