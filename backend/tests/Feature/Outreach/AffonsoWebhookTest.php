<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Http\Middleware\VerifyAffonsoSignature;
use App\Models\Event;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Services\Outreach\OutreachSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Affonso → SpiderNet: signed webhooks mark prospects signed up. */
class AffonsoWebhookTest extends OutreachTestCase
{
    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectMailbox();
        $this->connectAffonso(self::SECRET);
    }

    private function invited(string $handle = 'creator', string $email = 'creator@example.test'): PartnerProspect
    {
        $this->import([['name' => ucfirst($handle), 'domain' => "https://www.tiktok.com/@{$handle}", 'primary' => 'x', 'email' => $email]]);
        app(OutreachSender::class)->runForTenant($this->tenant->refresh(), false, true);

        return PartnerProspect::forTenant($this->tenant->id)->where('handle', $handle)->firstOrFail();
    }

    private function event(string $type, array $data = [], string $id = 'evt_1'): array
    {
        return ['id' => $id, 'type' => $type, 'created_at' => now()->toIso8601String(), 'data' => array_merge([
            'affiliateId' => 'aff_1', 'affiliateProgramId' => 'prog_1', 'trackingId' => 'creator', 'status' => 'ACTIVE',
            'email' => null, 'name' => 'Creator', 'externalUserId' => null, 'metadata' => [], 'groupId' => 'grp_1',
        ], $data)];
    }

    /** POST the raw JSON body so the signature covers exactly what Laravel receives. */
    private function deliver(array $payload, ?string $signature = null, ?string $tenantId = null): TestResponse
    {
        $body = (string) json_encode($payload);
        $signature ??= VerifyAffonsoSignature::sign($body, self::SECRET, now()->getTimestamp());

        return $this->call('POST', '/api/webhooks/affonso/'.($tenantId ?? $this->tenant->id), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_AFFONSO_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_unknown_tenants_bad_signatures_and_malformed_events_are_refused(): void
    {
        $payload = $this->event('affiliate.created');
        $body = (string) json_encode($payload);
        $now = now()->getTimestamp();

        $this->deliver($payload, null, (string) Str::uuid())->assertNotFound();
        $this->deliver($payload, null, 'not-a-uuid')->assertNotFound();

        $bare = Tenant::create([
            'id' => Str::uuid(), 'name' => 'No connector', 'slug' => 'bare-'.Str::lower(Str::random(6)), 'status' => 'active',
            'plan' => 'growth', 'automation_level' => 'assisted', 'onboarding_completed_at' => now(), 'settings' => [],
        ]);
        $this->deliver($payload, null, (string) $bare->id)->assertNotFound();

        $this->deliver($payload, 'nonsense')->assertUnauthorized();
        $this->deliver($payload, VerifyAffonsoSignature::sign($body, 'wrong-secret', $now))->assertUnauthorized();
        $this->deliver($payload, VerifyAffonsoSignature::sign($body, self::SECRET, $now - 400))->assertUnauthorized();
        $this->deliver($payload, VerifyAffonsoSignature::sign($body.' ', self::SECRET, $now))->assertUnauthorized();

        $this->deliver(['type' => 'affiliate.created'])->assertStatus(422);
        $this->deliver(['id' => 'evt_x'])->assertStatus(422);

        $this->assertSame(0, DB::table('webhook_receipts')->count());
    }

    public function test_signup_by_external_user_id_is_applied_once_and_later_events_keep_status_in_sync(): void
    {
        $prospect = $this->invited();
        $payload = $this->event('affiliate.created', ['externalUserId' => $prospect->lead_id]);

        $this->deliver($payload)->assertOk()->assertJson(['received' => true]);

        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_SIGNED_UP, $prospect->status);
        $this->assertSame('aff_1', $prospect->affonso_affiliate_id);
        $this->assertSame('creator', $prospect->affonso_tracking_id);
        $this->assertSame('ACTIVE', $prospect->affiliate_status);
        $this->assertNotNull($prospect->signed_up_at);
        $this->assertNull($prospect->next_send_at);
        $this->assertSame('won', $prospect->lead->stage);

        $receipt = DB::table('webhook_receipts')->where('event_id', 'evt_1')->first();
        $this->assertSame('affonso', $receipt->provider);
        $this->assertNotNull($receipt->processed_at);
        $this->assertNull($receipt->error);
        $event = Event::where('tenant_id', $this->tenant->id)->where('event_type', 'outreach.affiliate.created')->firstOrFail();
        $this->assertSame('webhook', $event->payload['via']);

        // Redelivery: acknowledged, not reprocessed.
        $this->deliver($payload)->assertOk()->assertJson(['received' => true, 'duplicate' => true]);
        $this->assertSame(1, DB::table('webhook_receipts')->count());

        $this->deliver($this->event('affiliate.updated', ['externalUserId' => $prospect->lead_id, 'status' => 'INACTIVE'], 'evt_2'))->assertOk();
        $this->assertSame('INACTIVE', $prospect->refresh()->affiliate_status);
        $this->assertSame(PartnerProspect::STATUS_SIGNED_UP, $prospect->status);

        $this->deliver($this->event('affiliate.deleted', ['externalUserId' => $prospect->lead_id], 'evt_3'))->assertOk();
        $this->assertSame('DELETED', $prospect->refresh()->affiliate_status);
        $this->assertSame(PartnerProspect::STATUS_SIGNED_UP, $prospect->status);
    }

    public function test_a_confirmed_affiliate_clears_a_bot_claimed_signup_flag(): void
    {
        $prospect = $this->invited();
        // What the bot leaves behind when a creator says "I already joined".
        PartnerProspect::whereKey($prospect->id)->update([
            'status' => PartnerProspect::STATUS_SIGNED_UP, 'signed_up_at' => now(),
            'needs_human_at' => now(), 'needs_human_reason' => 'verify_signup',
        ]);

        $this->deliver($this->event('affiliate.confirmed', ['externalUserId' => $prospect->lead_id]))->assertOk();

        $prospect->refresh();
        $this->assertSame('aff_1', $prospect->affonso_affiliate_id);
        $this->assertNull($prospect->needs_human_at);
        $this->assertNull($prospect->needs_human_reason);
    }

    public function test_matches_by_prospect_token_then_email_and_parks_strangers(): void
    {
        $alpha = $this->invited('alpha', 'alpha@example.test');
        $beta = $this->invited('beta', 'beta@example.test');

        $this->deliver($this->event('affiliate.confirmed', ['metadata' => ['prospect_token' => strtoupper($alpha->invite_token)]], 'evt_a'))->assertOk();
        $this->deliver($this->event('affiliate.confirmed', ['email' => 'Beta@Example.test', 'affiliateId' => 'aff_b'], 'evt_b'))->assertOk();
        $this->deliver($this->event('affiliate.created', ['email' => 'nobody@example.test'], 'evt_c'))->assertOk();

        $this->assertSame(PartnerProspect::STATUS_SIGNED_UP, $alpha->refresh()->status);
        $this->assertSame('aff_1', $alpha->affonso_affiliate_id);
        $this->assertSame(PartnerProspect::STATUS_SIGNED_UP, $beta->refresh()->status);
        $this->assertSame('aff_b', $beta->affonso_affiliate_id);

        $stranger = DB::table('webhook_receipts')->where('event_id', 'evt_c')->first();
        $this->assertSame('unmatched', $stranger->error);
        $this->assertNotNull($stranger->processed_at);
        $this->assertSame(2, PartnerProspect::forTenant($this->tenant->id)->where('status', PartnerProspect::STATUS_SIGNED_UP)->count());
    }
}
