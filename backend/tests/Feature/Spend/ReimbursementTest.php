<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Models\Reimbursement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReimbursementTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Reimb Tenant',
            'slug' => 'reimb-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role = 'member'): User
    {
        return User::create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }

    /** Submit a small report (no approval policy) so it auto-approves and creates a reimbursement. */
    private function createPendingReimbursement(User $submitter): Reimbursement
    {
        $report = $this->actingAs($submitter, 'sanctum')
            ->postJson('/api/financial/expenses', [
                'title' => 'Petty cash',
                'items' => [[
                    'description' => 'Parking',
                    'amount' => '18.00',
                    'expense_date' => now()->toDateString(),
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($submitter, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        return Reimbursement::where('expense_report_id', $report['id'])->firstOrFail();
    }

    public function test_mark_paid_without_step_up_returns_428(): void
    {
        $tenant = $this->createTenant();
        $submitter = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $reimbursement = $this->createPendingReimbursement($submitter);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/reimbursements/'.$reimbursement->id.'/mark-paid', [
                'reference' => 'BANK-REF-1',
            ])
            ->assertStatus(428)
            ->assertJsonPath('error', 'step_up_required');

        $this->assertSame('pending', $reimbursement->fresh()->status);
    }

    public function test_mark_paid_forbidden_for_member(): void
    {
        $tenant = $this->createTenant();
        $submitter = $this->createUser($tenant, 'member');
        $reimbursement = $this->createPendingReimbursement($submitter);

        $this->actingAs($submitter, 'sanctum')
            ->postJson('/api/financial/reimbursements/'.$reimbursement->id.'/mark-paid', [
                'reference' => 'BANK-REF-1',
            ])
            ->assertForbidden();
    }

    public function test_mark_paid_with_fresh_step_up_succeeds(): void
    {
        $tenant = $this->createTenant();
        $submitter = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $admin->update(['step_up_at' => now()]);
        $reimbursement = $this->createPendingReimbursement($submitter);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/reimbursements/'.$reimbursement->id.'/mark-paid', [
                'reference' => 'BANK-REF-99',
                'method' => 'bank_transfer',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.reference', 'BANK-REF-99');

        $fresh = $reimbursement->fresh();
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame('reimbursed', $fresh->report->status);
        $this->assertDatabaseHas('event_log', ['event_type' => 'reimbursement.paid']);

        // Cannot pay twice.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/reimbursements/'.$reimbursement->id.'/mark-paid')
            ->assertStatus(409);
    }

    public function test_cancel_is_admin_gated_and_records_reason(): void
    {
        $tenant = $this->createTenant();
        $submitter = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $reimbursement = $this->createPendingReimbursement($submitter);

        $this->actingAs($submitter, 'sanctum')
            ->postJson('/api/financial/reimbursements/'.$reimbursement->id.'/cancel')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/reimbursements/'.$reimbursement->id.'/cancel', [
                'reason' => 'duplicate claim',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.notes', 'duplicate claim');

        $this->assertDatabaseHas('event_log', ['event_type' => 'reimbursement.cancelled']);
    }

    public function test_index_scopes_non_admins_to_own_reimbursements(): void
    {
        $tenant = $this->createTenant();
        $alice = $this->createUser($tenant, 'member');
        $bob = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');

        $this->createPendingReimbursement($alice);
        $this->createPendingReimbursement($bob);

        $mine = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/financial/reimbursements')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $mine['total']);

        $all = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/financial/reimbursements')
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $all['total']);
    }
}
