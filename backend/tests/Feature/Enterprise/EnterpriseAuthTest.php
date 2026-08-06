<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Mail\BrandedMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * /api/enterprise/auth/* — the sign-in methods the marketing-site SignInPage
 * calls. Pins the SPA contract: `{"detail": ...}` error envelopes, the
 * UnifiedAuthSession success envelope, demo-flag gating (real users only),
 * and the magic-link single-use lifecycle.
 */
class EnterpriseAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The encrypted totp_secret cast needs a key the phpunit env doesn't set.
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Demo behavior off by default — tests flip it on per-case.
        config()->set('enterprise.demo_auth_enabled', false);
        config()->set('enterprise.totp_login_enabled', true);
        config()->set('enterprise.signin_url', 'https://spidernetos.com/sign-in');
    }

    private function makeTenant(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'id' => Str::uuid(),
            'name' => 'Auth Test Co',
            'slug' => 'auth-test-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
        ], $overrides));
    }

    private function makeUser(Tenant $tenant, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Auth User',
            'email' => 'auth-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ], $overrides));
    }

    /** Enroll TOTP for a user; returns the shared secret. */
    private function enrollTotp(User $user): string
    {
        $secret = app(MfaService::class)->generateSecret();
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

        return $secret;
    }

    private function assertSessionEnvelope($response, User $user): void
    {
        $response->assertOk();
        $data = $response->json();
        $this->assertNotEmpty($data['access_token']);
        $this->assertSame($user->email, $data['user']['email']);
        $this->assertArrayHasKey('tenant', $data);
        $this->assertIsArray($data['caps']);
        $this->assertNotEmpty($data['caps']);
    }

    // ── TOTP ────────────────────────────────────────────────────────────

    public function test_totp_login_succeeds_for_enrolled_user(): void
    {
        $user = $this->makeUser($this->makeTenant());
        $secret = $this->enrollTotp($user);
        $code = app(MfaService::class)->codeAt($secret, time());

        $response = $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => $user->email,
            'code' => $code,
        ]);

        $this->assertSessionEnvelope($response, $user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_totp_login_rejects_wrong_code(): void
    {
        $user = $this->makeUser($this->makeTenant());
        $this->enrollTotp($user);

        $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => $user->email,
            'code' => '999999',
        ])->assertStatus(401)->assertJson(['detail' => 'invalid TOTP code']);
    }

    public function test_totp_login_rejects_unenrolled_user_with_generic_detail(): void
    {
        $user = $this->makeUser($this->makeTenant());

        $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertStatus(401)->assertJson(['detail' => 'invalid TOTP code']);
    }

    public function test_totp_login_rejects_unknown_email_with_generic_detail(): void
    {
        $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => 'nobody@nowhere.test',
            'code' => '123456',
        ])->assertStatus(401)->assertJson(['detail' => 'invalid TOTP code']);
    }

    public function test_totp_login_selects_correct_user_among_email_twins(): void
    {
        $email = 'twin-'.Str::lower(Str::random(6)).'@test.test';
        $userA = $this->makeUser($this->makeTenant(), ['email' => $email]);
        $userB = $this->makeUser($this->makeTenant(), ['email' => $email]);
        $this->enrollTotp($userA);
        $secretB = $this->enrollTotp($userB);

        // A code minted from B's secret must sign in B, not A.
        $response = $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => $email,
            'code' => app(MfaService::class)->codeAt($secretB, time()),
        ]);

        $response->assertOk();
        $this->assertSame($userB->id, $response->json('user.id'));
    }

    public function test_totp_demo_bypass_only_when_demo_flag_on(): void
    {
        $user = $this->makeUser($this->makeTenant());

        // Flag off: 000000 is just a wrong code.
        $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => $user->email,
            'code' => '000000',
        ])->assertStatus(401);

        // Flag on + existing user: session issued.
        config()->set('enterprise.demo_auth_enabled', true);
        $this->assertSessionEnvelope($this->postJson('/api/enterprise/auth/totp/login', [
            'email' => $user->email,
            'code' => '000000',
        ]), $user);

        // Flag on + unknown email: never fabricate users.
        $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => 'ghost@nowhere.test',
            'code' => '000000',
        ])->assertStatus(401);
    }

    public function test_totp_login_per_email_throttle(): void
    {
        $user = $this->makeUser($this->makeTenant());
        $this->enrollTotp($user);

        // Prime the per-email limiter directly (six HTTP calls would trip the
        // shared IP throttle first and mask the branch under test).
        $key = 'totp:'.sha1(strtolower($user->email));
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => $user->email,
            'code' => '111111',
        ])->assertStatus(429)->assertJson(['detail' => 'Too many attempts. Try again in a minute.']);
    }

    public function test_totp_login_disabled_by_kill_switch(): void
    {
        config()->set('enterprise.totp_login_enabled', false);
        $user = $this->makeUser($this->makeTenant());
        $secret = $this->enrollTotp($user);

        $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => $user->email,
            'code' => app(MfaService::class)->codeAt($secret, time()),
        ])->assertStatus(400)->assertJson(['detail' => 'TOTP sign-in is not enabled.']);
    }

    public function test_totp_login_ignores_stale_bearer_header(): void
    {
        $user = $this->makeUser($this->makeTenant());
        $secret = $this->enrollTotp($user);

        // The SPA's axios interceptor attaches any stored token, even on
        // login calls — a garbage bearer must not break the route.
        $this->withHeader('Authorization', 'Bearer stale-garbage-token')
            ->postJson('/api/enterprise/auth/totp/login', [
                'email' => $user->email,
                'code' => app(MfaService::class)->codeAt($secret, time()),
            ])->assertOk();
    }

    // ── Magic link ──────────────────────────────────────────────────────

    public function test_magic_link_request_mints_row_and_sends_email(): void
    {
        Mail::fake();
        $user = $this->makeUser($this->makeTenant());

        $this->postJson('/api/enterprise/auth/magic-link/request', [
            'email' => $user->email,
        ])->assertOk()->assertJson(['sent' => true]);

        Mail::assertSent(BrandedMail::class, fn ($mail) => $mail->hasTo($user->email));
        $row = DB::table('magic_links')->where('email', $user->email)->first();
        $this->assertNotNull($row);
        $this->assertSame((string) $user->id, (string) $row->user_id);
        $this->assertSame(64, strlen($row->token_hash));
    }

    public function test_magic_link_request_unknown_email_returns_sent_true_and_mints_nothing(): void
    {
        Mail::fake();

        $this->postJson('/api/enterprise/auth/magic-link/request', [
            'email' => 'ghost@nowhere.test',
        ])->assertOk()->assertJson(['sent' => true]);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('magic_links')->count());
    }

    public function test_magic_link_request_dev_link_only_when_demo_enabled(): void
    {
        Mail::fake();
        $user = $this->makeUser($this->makeTenant());

        $off = $this->postJson('/api/enterprise/auth/magic-link/request', ['email' => $user->email]);
        $this->assertArrayNotHasKey('dev_link', $off->json());

        config()->set('enterprise.demo_auth_enabled', true);
        $on = $this->postJson('/api/enterprise/auth/magic-link/request', ['email' => $user->email]);
        $this->assertIsString($on->json('dev_link.token'));
        $this->assertGreaterThan(0, $on->json('dev_link.expires_in'));
    }

    public function test_magic_link_verify_issues_session_and_replay_fails(): void
    {
        Mail::fake();
        config()->set('enterprise.demo_auth_enabled', true);
        $user = $this->makeUser($this->makeTenant());

        $token = $this->postJson('/api/enterprise/auth/magic-link/request', ['email' => $user->email])
            ->json('dev_link.token');

        $this->assertSessionEnvelope(
            $this->postJson('/api/enterprise/auth/magic-link/verify', ['token' => $token]),
            $user,
        );
        $this->assertNotNull($user->fresh()->email_verified_at);

        // Single-use: replay must fail with the mock's exact string.
        $this->postJson('/api/enterprise/auth/magic-link/verify', ['token' => $token])
            ->assertStatus(400)
            ->assertJson(['detail' => 'magic link already used or invalid']);
    }

    public function test_magic_link_verify_expired_400(): void
    {
        Mail::fake();
        config()->set('enterprise.demo_auth_enabled', true);
        $user = $this->makeUser($this->makeTenant());

        $token = $this->postJson('/api/enterprise/auth/magic-link/request', ['email' => $user->email])
            ->json('dev_link.token');

        $this->travel(31)->minutes();

        $this->postJson('/api/enterprise/auth/magic-link/verify', ['token' => $token])
            ->assertStatus(400)
            ->assertJson(['detail' => 'magic link expired']);
    }

    public function test_magic_link_verify_garbage_token_400(): void
    {
        $this->postJson('/api/enterprise/auth/magic-link/verify', [
            'token' => str_repeat('x', 48),
        ])->assertStatus(400)->assertJson(['detail' => 'invalid magic link']);
    }

    public function test_magic_link_binds_oldest_user_on_email_collision(): void
    {
        Mail::fake();
        config()->set('enterprise.demo_auth_enabled', true);
        $email = 'twin-'.Str::lower(Str::random(6)).'@test.test';
        $older = $this->makeUser($this->makeTenant(), ['email' => $email, 'created_at' => now()->subDay()]);
        $this->makeUser($this->makeTenant(), ['email' => $email]);

        $token = $this->postJson('/api/enterprise/auth/magic-link/request', ['email' => $email])
            ->json('dev_link.token');

        $response = $this->postJson('/api/enterprise/auth/magic-link/verify', ['token' => $token]);
        $response->assertOk();
        $this->assertSame($older->id, $response->json('user.id'));
    }

    // ── SSO ─────────────────────────────────────────────────────────────

    public function test_sso_demo_provider_completes_with_real_admin_session(): void
    {
        config()->set('enterprise.demo_auth_enabled', true);
        $tenant = $this->makeTenant(['slug' => 'demo-sso-'.Str::lower(Str::random(6))]);
        $this->makeUser($tenant, ['role' => 'member']);
        $admin = $this->makeUser($tenant, ['role' => 'admin']);

        $response = $this->postJson('/api/enterprise/auth/sso/start', [
            'tenant_slug' => $tenant->slug,
            'provider' => 'oidc-demo',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('completed'));
        // Highest-role REAL user from the DB — never a fabricated identity.
        $this->assertSame($admin->id, $response->json('user.id'));
        $this->assertSame($tenant->slug, $response->json('tenant.slug'));
    }

    public function test_sso_demo_provider_400_when_demo_off(): void
    {
        $this->postJson('/api/enterprise/auth/sso/start', [
            'tenant_slug' => 'demo',
            'provider' => 'oidc-demo',
        ])->assertStatus(400)->assertJson(['detail' => 'Demo SSO is not enabled in this environment.']);
    }

    public function test_sso_demo_unknown_tenant_404(): void
    {
        config()->set('enterprise.demo_auth_enabled', true);

        $this->postJson('/api/enterprise/auth/sso/start', [
            'tenant_slug' => 'no-such-tenant',
            'provider' => 'oidc-demo',
        ])->assertStatus(404)->assertJson(['detail' => 'tenant not found']);
    }

    public function test_sso_real_provider_returns_honest_400(): void
    {
        $response = $this->postJson('/api/enterprise/auth/sso/start', [
            'tenant_slug' => 'acme',
            'provider' => 'okta',
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('IdP configuration', $response->json('detail'));
        $this->assertNull($response->json('authorization_url'));
    }

    public function test_sso_callback_redirects_to_signin_with_error_param(): void
    {
        $response = $this->get('/api/enterprise/auth/sso/callback?state=whatever');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://spidernetos.com/sign-in?sso_error=', $location);
        // Never a session from the callback.
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    // ── WebAuthn ────────────────────────────────────────────────────────

    public function test_webauthn_demo_on_issues_session_for_existing_user(): void
    {
        config()->set('enterprise.demo_auth_enabled', true);
        $user = $this->makeUser($this->makeTenant());

        $this->assertSessionEnvelope(
            $this->postJson('/api/enterprise/auth/webauthn/login', ['email' => $user->email]),
            $user,
        );
    }

    public function test_webauthn_demo_on_unknown_email_401(): void
    {
        config()->set('enterprise.demo_auth_enabled', true);

        $this->postJson('/api/enterprise/auth/webauthn/login', ['email' => 'ghost@nowhere.test'])
            ->assertStatus(401)
            ->assertJson(['detail' => 'Passkey not registered for this user.']);
    }

    public function test_webauthn_off_400_not_available(): void
    {
        $user = $this->makeUser($this->makeTenant());

        $this->postJson('/api/enterprise/auth/webauthn/login', ['email' => $user->email])
            ->assertStatus(400)
            ->assertJson(['detail' => 'WebAuthn sign-in is not yet available.']);
    }

    // ── Cross-cutting ───────────────────────────────────────────────────

    public function test_suspended_tenant_blocked_403(): void
    {
        $user = $this->makeUser($this->makeTenant(['status' => 'suspended']));
        $secret = $this->enrollTotp($user);

        $this->postJson('/api/enterprise/auth/totp/login', [
            'email' => $user->email,
            'code' => app(MfaService::class)->codeAt($secret, time()),
        ])->assertStatus(403)->assertJson(['detail' => 'This workspace is suspended. Contact support.']);
    }

    public function test_password_login_error_includes_detail_key(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@nowhere.test',
            'password' => 'wrong',
        ])->assertStatus(422)->assertJson(['detail' => 'Invalid email or password.']);
    }
}
