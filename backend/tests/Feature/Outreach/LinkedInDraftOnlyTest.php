<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Models\Lead;
use App\Models\PackEntitlement;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Outreach\Channels\ChannelCannotSendException;
use App\Services\Outreach\Channels\LinkedInSafetyGovernor;
use App\Services\Outreach\Channels\LinkedInToSGate;
use App\Services\Outreach\Channels\ManualLinkedInChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Richard on LinkedIn (plan D7 §3), slice 1: he drafts, a person sends.
 *
 * LinkedIn's User Agreement prohibits automated access. Draft-only is the
 * default because it is the only LinkedIn outreach that does not touch that
 * agreement at all — a human opens LinkedIn and sends the message themselves.
 * Everything else here exists for the day somebody chooses to go further.
 */
class LinkedInDraftOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Outreach Co', 'slug' => 'out-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
            'settings' => ['outreach' => ['sending' => ['timezone' => 'Africa/Harare']]],
        ]);
        $this->admin = User::create([
            'name' => 'Jason', 'email' => 'j-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        // /api/sales/* is gated by pack.entitled:sales-crm.
        PackEntitlement::create([
            'tenant_id' => $this->tenant->id, 'pack_id' => 'sales-crm', 'source' => 'grant',
            'provider' => 'manual', 'status' => 'active', 'purchased_at' => now(),
        ]);
    }

    private function prospect(): PartnerProspect
    {
        $lead = Lead::create([
            'id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Amara Ncube', 'stage' => 'captured',
        ]);

        return PartnerProspect::create([
            'id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'lead_id' => $lead->id,
            'platform' => 'other', 'profile_url' => 'https://www.linkedin.com/in/amara',
            'profile_url_hash' => hash('sha256', 'https://www.linkedin.com/in/amara'),
            'display_name' => 'Amara Ncube', 'source' => 'manual',
            'invite_token' => Str::lower(Str::random(22)), 'status' => 'new',
        ]);
    }

    // ------------------------------------------------------------------ //
    //  The channel
    // ------------------------------------------------------------------ //

    public function test_the_default_channel_drafts_and_refuses_to_send(): void
    {
        $channel = new ManualLinkedInChannel;
        $prospect = $this->prospect();

        $this->assertFalse($channel->canSend());

        $draft = $channel->draft((string) $this->tenant->id, (string) $prospect->id, [
            'kind' => 'connect',
            'body' => 'Saw your post about month-end at the kitchen table — we close cafés\' books by the 5th.',
        ]);

        $this->assertSame('manual_linkedin', $draft['channel']);
        $this->assertFalse($draft['sendable_here']);
        $this->assertFalse($draft['over_limit']);
        $this->assertNotNull($prospect->fresh()->dm_draft_at);

        // A silent no-op on send would look exactly like a successful send in
        // every log and dashboard, so it throws.
        $this->expectException(ChannelCannotSendException::class);
        $channel->send((string) $this->tenant->id, (string) $prospect->id, $draft);
    }

    public function test_a_draft_over_linkedins_limit_is_flagged_not_truncated(): void
    {
        $channel = new ManualLinkedInChannel;
        $prospect = $this->prospect();

        $long = str_repeat('a', ManualLinkedInChannel::MAX_CONNECT_CHARS + 40);
        $draft = $channel->draft((string) $this->tenant->id, (string) $prospect->id, ['kind' => 'connect', 'body' => $long]);

        $this->assertTrue($draft['over_limit']);
        $this->assertSame(ManualLinkedInChannel::MAX_CONNECT_CHARS, $draft['limit']);
        // Silently cutting someone's outreach mid-sentence is worse than
        // telling them it is long.
        $this->assertSame(mb_strlen($long), mb_strlen($draft['body']));

        // A message gets the longer allowance.
        $this->assertSame(
            ManualLinkedInChannel::MAX_MESSAGE_CHARS,
            $channel->draft((string) $this->tenant->id, (string) $prospect->id, ['kind' => 'message', 'body' => $long])['limit'],
        );
    }

    public function test_an_empty_draft_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ManualLinkedInChannel)->draft((string) $this->tenant->id, (string) $this->prospect()->id, ['body' => '   ']);
    }

    // ------------------------------------------------------------------ //
    //  The terms gate
    // ------------------------------------------------------------------ //

    public function test_draft_only_needs_no_acknowledgement_and_is_the_default(): void
    {
        $gate = app(LinkedInToSGate::class);
        $settings = $gate->settings($this->tenant);

        $this->assertSame(LinkedInToSGate::MODE_DRAFT_ONLY, $settings['mode']);
        $this->assertFalse($settings['ack_current']);
        // Drafting a message a human then types into LinkedIn is not automated
        // access, so draft-only stays usable without anyone signing anything.
        $this->assertNotEmpty($settings['ack_text']);
        $this->assertSame(['allowed' => false, 'reason' => 'this workspace is in draft-only mode'], $gate->allowsAutomation($this->tenant));
    }

    public function test_assisted_mode_without_the_acknowledgement_is_refused_rather_than_downgraded(): void
    {
        $gate = app(LinkedInToSGate::class);

        try {
            $gate->update($this->tenant, $this->admin, LinkedInToSGate::MODE_ASSISTED, acknowledge: false);
            $this->fail('Assisted mode was accepted without the acknowledgement.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('acknowledgement', $e->getMessage());
        }

        // A settings screen that accepts a change and then does something else
        // is worse than one that says no.
        $this->assertSame(LinkedInToSGate::MODE_DRAFT_ONLY, $gate->settings($this->tenant->fresh())['mode']);
    }

    public function test_the_acknowledgement_records_who_and_when_and_which_version(): void
    {
        $gate = app(LinkedInToSGate::class);

        $settings = $gate->update($this->tenant, $this->admin, LinkedInToSGate::MODE_ASSISTED, acknowledge: true);

        $this->assertSame(LinkedInToSGate::MODE_ASSISTED, $settings['mode']);
        // An acknowledgement with no name on it is not an acknowledgement.
        $this->assertSame($this->admin->email, $settings['tos_acknowledged_by']);
        $this->assertSame(LinkedInToSGate::ACK_VERSION, $settings['tos_acknowledged_version']);
        $this->assertNotNull($settings['tos_acknowledged_at']);
        $this->assertTrue($gate->allowsAutomation($this->tenant->fresh())['allowed']);
    }

    public function test_an_acknowledgement_of_an_old_version_does_not_count(): void
    {
        $gate = app(LinkedInToSGate::class);
        $gate->update($this->tenant, $this->admin, LinkedInToSGate::MODE_ASSISTED, acknowledge: true);

        // The wording of the risk changed; agreeing to the old text is not
        // agreeing to the new one.
        $settings = $this->tenant->fresh()->settings;
        data_set($settings, LinkedInToSGate::SETTINGS_KEY.'.tos_acknowledged_version', '2020-01-01.1');
        $this->tenant->forceFill(['settings' => $settings])->save();

        $tenant = $this->tenant->fresh();
        $this->assertFalse($gate->acknowledged($tenant));
        $verdict = $gate->allowsAutomation($tenant);
        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('acknowledgement has not been given', $verdict['reason']);
    }

    // ------------------------------------------------------------------ //
    //  The safety governor
    // ------------------------------------------------------------------ //

    public function test_a_brand_new_sender_is_held_to_a_warm_up_allowance(): void
    {
        $governor = app(LinkedInSafetyGovernor::class);

        // No recorded start: treated as brand new. Fail slow, not fast — the
        // cost of being wrong is somebody's LinkedIn account.
        $this->assertSame(LinkedInSafetyGovernor::WARMUP_FIRST_DAY_CONNECTS, $governor->weeklyCap($this->tenant));

        $this->startSender(now()->subDays(7));
        $mid = $governor->weeklyCap($this->tenant->fresh());
        $this->assertGreaterThan(LinkedInSafetyGovernor::WARMUP_FIRST_DAY_CONNECTS, $mid);
        $this->assertLessThan(LinkedInSafetyGovernor::MAX_CONNECTS_PER_WEEK, $mid);

        $this->startSender(now()->subDays(LinkedInSafetyGovernor::WARMUP_DAYS + 1));
        $this->assertSame(LinkedInSafetyGovernor::MAX_CONNECTS_PER_WEEK, $governor->weeklyCap($this->tenant->fresh()));
    }

    public function test_quiet_hours_stop_sends_and_say_when_they_resume(): void
    {
        $governor = app(LinkedInSafetyGovernor::class);
        $this->startSender(now()->subDays(30));

        // 22:00 in Harare — a human does not send forty messages then.
        $night = Carbon::parse('2026-09-19 20:00:00', 'Africa/Harare');
        $verdict = $governor->check($this->tenant->fresh(), LinkedInSafetyGovernor::ACTION_CONNECT, $night);
        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('quiet hours', $verdict['reason']);
        // A refusal without a time is an outage; with one it is a schedule.
        $this->assertNotNull($verdict['retry_after']);
        $this->assertSame(LinkedInSafetyGovernor::QUIET_UNTIL_HOUR, (int) Carbon::parse($verdict['retry_after'])->setTimezone('Africa/Harare')->format('G'));

        $day = Carbon::parse('2026-09-19 10:00:00', 'Africa/Harare');
        $this->assertTrue($governor->check($this->tenant->fresh(), LinkedInSafetyGovernor::ACTION_CONNECT, $day)['allowed']);
    }

    public function test_the_weekly_connection_cap_holds(): void
    {
        $governor = app(LinkedInSafetyGovernor::class);
        $this->startSender(now()->subDays(60));

        $this->recordSends(LinkedInSafetyGovernor::MAX_CONNECTS_PER_WEEK, now()->subDays(2));

        $verdict = $governor->check($this->tenant->fresh(), LinkedInSafetyGovernor::ACTION_CONNECT, Carbon::parse('2026-09-19 10:00:00', 'Africa/Harare'));
        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('weekly connection limit', $verdict['reason']);
        $this->assertSame(0, $verdict['remaining']['connects_this_week']);
    }

    public function test_sends_outside_the_window_do_not_count_against_it(): void
    {
        $governor = app(LinkedInSafetyGovernor::class);
        $this->startSender(now()->subDays(60));

        $this->recordSends(LinkedInSafetyGovernor::MAX_CONNECTS_PER_WEEK, now()->subDays(9));

        $verdict = $governor->check($this->tenant->fresh(), LinkedInSafetyGovernor::ACTION_CONNECT, Carbon::parse('2026-09-19 10:00:00', 'Africa/Harare'));
        $this->assertTrue($verdict['allowed']);
        $this->assertSame(LinkedInSafetyGovernor::MAX_CONNECTS_PER_WEEK, $verdict['remaining']['connects_this_week']);
    }

    // ------------------------------------------------------------------ //
    //  API
    // ------------------------------------------------------------------ //

    public function test_the_settings_endpoint_shows_the_mode_the_text_and_the_limits(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/sales/partners/linkedin/settings')->assertOk();

        $this->assertSame('draft_only', $response->json('data.mode'));
        $this->assertFalse($response->json('data.ack_current'));
        $this->assertFalse($response->json('data.channel.can_send'));
        $this->assertSame(300, $response->json('data.limits.connect_chars'));
        $this->assertSame(100, $response->json('data.limits.connects_per_week'));
        // The screen shows what is being agreed to, not a checkbox next to a link.
        $this->assertStringContainsString("LinkedIn's User Agreement", $response->json('data.ack_text'));
    }

    public function test_the_settings_endpoint_refuses_assisted_without_the_acknowledgement(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/sales/partners/linkedin/settings', ['mode' => 'assisted'])
            ->assertStatus(422)->assertJsonPath('reason', 'tos_not_acknowledged');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/sales/partners/linkedin/settings', ['mode' => 'assisted', 'acknowledge_terms' => true])
            ->assertOk()->assertJsonPath('data.mode', 'assisted')->assertJsonPath('data.ack_current', true);

        // Back to draft-only whenever they like.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/sales/partners/linkedin/settings', ['mode' => 'draft_only'])
            ->assertOk()->assertJsonPath('data.mode', 'draft_only');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/sales/partners/linkedin/settings', ['mode' => 'nonsense'])->assertStatus(422);
    }

    public function test_changing_the_mode_is_admin_only(): void
    {
        $member = User::create([
            'name' => 'Member', 'email' => 'm-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'member', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($member, 'sanctum')
            ->putJson('/api/sales/partners/linkedin/settings', ['mode' => 'assisted', 'acknowledge_terms' => true])
            ->assertForbidden();
        $this->actingAs($member, 'sanctum')->getJson('/api/sales/partners/linkedin/settings')->assertOk();
    }

    public function test_the_linkedin_routes_require_authentication(): void
    {
        $this->getJson('/api/sales/partners/linkedin/settings')->assertUnauthorized();
        $this->putJson('/api/sales/partners/linkedin/settings', ['mode' => 'draft_only'])->assertUnauthorized();
    }

    // ------------------------------------------------------------------ //

    private function startSender(\DateTimeInterface $at): void
    {
        $settings = $this->tenant->fresh()->settings;
        data_set($settings, LinkedInToSGate::SETTINGS_KEY.'.sender_started_at', Carbon::parse($at)->toIso8601String());
        $this->tenant->forceFill(['settings' => $settings])->save();
    }

    private function recordSends(int $count, \DateTimeInterface $at): void
    {
        for ($i = 0; $i < $count; $i++) {
            $lead = Lead::create(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'P'.$i, 'stage' => 'captured']);
            DB::table('partner_prospects')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'lead_id' => $lead->id,
                'platform' => 'other', 'profile_url' => 'https://www.linkedin.com/in/p'.$i,
                'profile_url_hash' => hash('sha256', 'p'.$i), 'all_urls' => '[]', 'source' => 'manual',
                'source_meta' => '{}', 'invite_token' => Str::lower(Str::random(22)), 'status' => 'contacted',
                'dm_sent_at' => $at, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
