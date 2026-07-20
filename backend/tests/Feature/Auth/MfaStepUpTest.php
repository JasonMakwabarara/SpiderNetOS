<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Requires Postgres. End-to-end MFA: enroll → confirm → step-up now demands a
 * real TOTP (or recovery code), and password-only step-up is rejected once
 * enrolled.
 */
class MfaStepUpTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Mfa Co', 'slug' => 'mfa-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'launch', 'onboarding_completed_at' => now(),
        ]);

        return User::create([
            'name' => 'Sec Admin', 'email' => 'sec@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('correct-horse'), 'tenant_id' => $tenant->id, 'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }

    public function test_enroll_confirm_then_stepup_requires_totp(): void
    {
        $user = $this->user();
        $mfa = app(MfaService::class);
        $auth = $this->actingAs($user, 'sanctum');

        // Enroll → get a secret.
        $secret = $auth->postJson('/api/auth/mfa/enroll')->assertOk()->json('secret');
        $this->assertNotEmpty($secret);

        // Not active until confirmed.
        $user->refresh();
        $this->assertFalse($user->hasMfaEnrolled());

        // Confirm with a valid code → recovery codes returned, MFA active.
        $confirm = $auth->postJson('/api/auth/mfa/confirm', ['code' => $mfa->codeAt($secret, time())]);
        $confirm->assertOk()->assertJsonPath('enrolled', true);
        $this->assertCount(10, $confirm->json('recovery_codes'));

        $user->refresh();
        $this->assertTrue($user->hasMfaEnrolled());

        // Step-up with password ONLY is now rejected.
        $auth->postJson('/api/auth/step-up', ['password' => 'correct-horse'])->assertStatus(422);

        // Step-up with a wrong code is rejected.
        $auth->postJson('/api/auth/step-up', ['password' => 'correct-horse', 'mfa_code' => '000000'])->assertStatus(422);

        // Step-up with password + valid TOTP succeeds.
        $auth->postJson('/api/auth/step-up', ['password' => 'correct-horse', 'mfa_code' => $mfa->codeAt($secret, time())])
            ->assertOk()->assertJsonPath('step_up.fresh', true);
    }

    public function test_recovery_code_is_single_use(): void
    {
        $user = $this->user();
        $mfa = app(MfaService::class);
        $auth = $this->actingAs($user, 'sanctum');

        $secret = $auth->postJson('/api/auth/mfa/enroll')->json('secret');
        $codes = $auth->postJson('/api/auth/mfa/confirm', ['code' => $mfa->codeAt($secret, time())])->json('recovery_codes');
        $recovery = $codes[0];

        // First use works.
        $auth->postJson('/api/auth/step-up', ['password' => 'correct-horse', 'mfa_code' => $recovery])->assertOk();
        // Second use of the same recovery code fails.
        $auth->postJson('/api/auth/step-up', ['password' => 'correct-horse', 'mfa_code' => $recovery])->assertStatus(422);
    }

    public function test_unenrolled_user_still_uses_password_only(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/step-up', ['password' => 'correct-horse'])
            ->assertOk()->assertJsonPath('step_up.fresh', true);
    }
}
