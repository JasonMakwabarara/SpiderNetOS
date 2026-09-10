<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\Connectors\ConnectorManager;
use App\Services\Messaging\TenantMailerFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The two partner-outreach connectors, Affonso (affiliate API) and the tenant
 * mailbox (SMTP/IMAP), through the generic connect / verify / execute flow.
 */
class OutreachConnectorsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private function user(): User
    {
        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Outreach Co', 'slug' => 'outreach-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);

        return User::create([
            'name' => 'Ops', 'email' => 'ops@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('pw'), 'tenant_id' => $this->tenant->id, 'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function bindMailerFactory(Mailer $mailer): TenantMailerFactory
    {
        $factory = new class(app(ConnectorManager::class), $mailer) extends TenantMailerFactory
        {
            public array $seen = [];

            public function __construct(ConnectorManager $connectors, private readonly Mailer $mailer)
            {
                parent::__construct($connectors);
            }

            public function fromCredentials(array $credentials): Mailer
            {
                $this->seen = $credentials;

                return $this->mailer;
            }
        };

        $this->app->instance(TenantMailerFactory::class, $factory);

        return $factory;
    }

    public function test_catalogue_lists_both_outreach_connectors(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/integrations/catalogue')->assertOk();
        $byProvider = collect($res->json('data'))->keyBy('provider');

        $this->assertSame('affiliate', $byProvider['affonso']['category']);
        $this->assertSame(['find_affiliate', 'create_affiliate'], $byProvider['affonso']['actions']);
        $this->assertContains('api_key', array_column($byProvider['affonso']['fields'], 'key'));

        $this->assertSame('email', $byProvider['zoho_mail']['category']);
        $smtpHost = collect($byProvider['zoho_mail']['fields'])->firstWhere('key', 'smtp_host');
        $this->assertSame('smtp.zoho.com', $smtpHost['default']);
    }

    public function test_affonso_connects_verifies_finds_and_creates_affiliates(): void
    {
        $user = $this->user();

        $existing = ['id' => 'aff_1', 'email' => 'creator@example.test', 'name' => 'Creator', 'tracking_id' => 'creator'];

        Http::fake(function (Request $request) use ($existing) {
            $this->assertSame('Bearer sk_live_test', $request->header('Authorization')[0]);

            if ($request->method() === 'POST') {
                return Http::response(['success' => true, 'data' => ['id' => 'aff_new'] + $request->data()], 201);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $search = (string) ($query['search'] ?? '');

            return Http::response([
                'data' => $search === '' || str_contains('creator@example.test', $search) ? [$existing] : [],
                'meta' => ['total' => 1],
            ], 200);
        });

        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/affonso/authorize', [
            'credentials' => ['api_key' => 'sk_live_test', 'program_id' => 'prog_1', 'group_id' => 'grp_1'],
        ])->assertCreated()->assertJsonPath('verified', true);

        $integration = TenantIntegration::forTenant($this->tenant->id)->where('provider', 'affonso')->firstOrFail();
        $this->assertSame('affiliate', $integration->type);
        $this->assertSame('connected', $integration->status);

        // Lookup: exact-match filter on top of the partial search Affonso does.
        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/affonso/actions/find_affiliate', [
            'params' => ['email' => 'creator@example.test'],
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.found', true)->assertJsonPath('data.affiliate.id', 'aff_1');

        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/affonso/actions/find_affiliate', [
            'params' => ['email' => 'nobody@example.test'],
        ])->assertOk()->assertJsonPath('data.found', false);

        // Create is idempotent on email: the existing affiliate comes back untouched.
        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/affonso/actions/create_affiliate', [
            'params' => ['email' => 'creator@example.test', 'name' => 'Creator'],
        ])->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.affiliate.id', 'aff_1');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        // A new email hits POST /v1/affiliates with the program and default group.
        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/affonso/actions/create_affiliate', [
            'params' => ['email' => 'new@example.test', 'name' => 'New Creator', 'external_user_id' => 'lead-1', 'metadata' => ['prospect_token' => 'tok']],
        ])->assertOk()->assertJsonPath('data.created', true)->assertJsonPath('data.affiliate.id', 'aff_new');

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && str_ends_with($r->url(), '/v1/affiliates')
                && $r['program_id'] === 'prog_1'
                && $r['group_id'] === 'grp_1'
                && $r['status'] === 'approved'
                && $r['external_user_id'] === 'lead-1'
                && $r['metadata']['prospect_token'] === 'tok'
                && $r['metadata']['spidernet_tenant_id'] === (string) $this->tenant->id;
        });
    }

    public function test_affonso_requires_api_key_and_program_id(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/affonso/authorize', [
            'credentials' => ['api_key' => 'sk_live_test'],
        ])->assertStatus(422)->assertJsonPath('missing_fields.0', 'program_id');
    }

    public function test_mailbox_connect_sends_a_test_email_through_the_tenant_smtp(): void
    {
        $user = $this->user();

        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->once()->withArgs(function (string $text, $callback) {
            return str_contains($text, 'connection test');
        })->andReturnNull();

        $factory = $this->bindMailerFactory($mailer);

        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/zoho_mail/authorize', [
            'credentials' => [
                'from_address' => 'partners@hannah-ai.test', 'from_name' => 'Hannah AI Partnerships',
                'smtp_host' => 'smtp.zoho.com', 'smtp_port' => '587',
                'smtp_username' => 'partners@hannah-ai.test', 'smtp_password' => 'app-pass',
            ],
        ])->assertCreated()->assertJsonPath('verified', true);

        $this->assertSame('app-pass', $factory->seen['smtp_password']);

        $integration = TenantIntegration::forTenant($this->tenant->id)->where('provider', 'zoho_mail')->firstOrFail();
        $this->assertSame('email', $integration->type);
        $this->assertSame('connected', $integration->status);

        // The stored credentials resolve back through the factory for later senders.
        $credentials = $factory->credentialsFor((string) $this->tenant->id);
        $this->assertTrue($factory->hasSmtp($credentials));
        $this->assertSame(
            ['address' => 'partners@hannah-ai.test', 'name' => 'Hannah AI Partnerships'],
            $factory->senderFor($credentials),
        );
    }

    public function test_mailbox_connect_reports_smtp_failure_without_500(): void
    {
        $user = $this->user();

        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->once()->andThrow(new \RuntimeException('535 Authentication failed'));
        $this->bindMailerFactory($mailer);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/integrations/zoho_mail/authorize', [
            'credentials' => [
                'from_address' => 'partners@hannah-ai.test', 'smtp_host' => 'smtp.zoho.com', 'smtp_port' => '587',
                'smtp_username' => 'partners@hannah-ai.test', 'smtp_password' => 'wrong',
            ],
        ]);

        $res->assertCreated()->assertJsonPath('verified', false);
        $this->assertStringContainsString('535', (string) $res->json('error'));
        $this->assertSame('error', TenantIntegration::forTenant($this->tenant->id)->where('provider', 'zoho_mail')->value('status'));
    }

    public function test_mailbox_requires_smtp_password(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/zoho_mail/authorize', [
            'credentials' => ['from_address' => 'partners@hannah-ai.test', 'smtp_host' => 'smtp.zoho.com', 'smtp_port' => '587', 'smtp_username' => 'x'],
        ])->assertStatus(422)->assertJsonPath('missing_fields.0', 'smtp_password');
    }
}
