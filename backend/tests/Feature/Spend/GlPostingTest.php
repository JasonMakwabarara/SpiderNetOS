<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Jobs\PostToGeneralLedgerJob;
use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use App\Models\GlPosting;
use App\Models\LedgerEntry;
use App\Models\SpendPostingRule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Financial\LedgerService;
use App\Services\Spend\Accounting\GlPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GlPostingTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'GL Tenant',
            'slug' => 'gl-'.Str::lower(Str::random(8)),
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

    /** @return array{0: ChartOfAccount, 1: ChartOfAccount, 2: ChartOfAccount} travel expense, meals expense, credit (payable) */
    private function seedChart(Tenant $tenant): array
    {
        $travel = ChartOfAccount::create([
            'tenant_id' => $tenant->id, 'code' => '6100', 'name' => 'Travel Expense', 'type' => 'expense',
        ]);
        $meals = ChartOfAccount::create([
            'tenant_id' => $tenant->id, 'code' => '6200', 'name' => 'Meals Expense', 'type' => 'expense',
        ]);
        $payable = ChartOfAccount::create([
            'tenant_id' => $tenant->id, 'code' => '2100', 'name' => 'Reimbursements Payable', 'type' => 'liability',
        ]);

        return [$travel, $meals, $payable];
    }

    private function createRules(Tenant $tenant, string $mode): SpendPostingRule
    {
        return SpendPostingRule::create([
            'tenant_id' => $tenant->id,
            'mode' => $mode,
            'expense_credit_account_code' => '2100',
            'bill_credit_account_code' => '2100',
            'enabled' => true,
        ]);
    }

    /** Create + submit an expense report; without an approval policy it auto-approves (which fires the projection -> job). */
    private function approveReport(Tenant $tenant, User $user, array $items): string
    {
        $report = $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses', ['title' => 'GL test report', 'items' => $items])
            ->assertCreated()
            ->json('data');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        return $report['id'];
    }

    public function test_auto_mode_posts_balanced_ledger_entries_on_approval(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        [$travel, $meals] = $this->seedChart($tenant);
        $this->createRules($tenant, 'auto');

        $travelCategory = ExpenseCategory::create([
            'tenant_id' => $tenant->id, 'name' => 'Travel', 'slug' => 'travel', 'gl_account_id' => $travel->id,
        ]);
        $mealsCategory = ExpenseCategory::create([
            'tenant_id' => $tenant->id, 'name' => 'Meals', 'slug' => 'meals', 'gl_account_id' => $meals->id,
        ]);

        $reportId = $this->approveReport($tenant, $user, [
            ['description' => 'Flight', 'amount' => '400.00', 'expense_date' => now()->toDateString(), 'category_id' => $travelCategory->id],
            ['description' => 'Taxi', 'amount' => '60.00', 'expense_date' => now()->toDateString(), 'category_id' => $travelCategory->id],
            ['description' => 'Dinner', 'amount' => '90.00', 'expense_date' => now()->toDateString(), 'category_id' => $mealsCategory->id],
        ]);

        $posting = GlPosting::forTenant($tenant->id)->forSource('expense_report', $reportId)->first();
        $this->assertNotNull($posting, 'approval should have produced a gl_postings row');
        $this->assertSame('posted', $posting->status);
        $this->assertNotNull($posting->transaction_number);
        $this->assertNotNull($posting->posted_at);
        $this->assertSame('550.0000', (string) $posting->amount);

        // Balanced compound journal: 2 grouped debit lines + 1 credit line.
        $entries = LedgerEntry::forTenant($tenant->id)
            ->where('transaction_id', $posting->transaction_number)
            ->get();
        $this->assertCount(3, $entries);

        $debits = $entries->where('side', 'debit');
        $credits = $entries->where('side', 'credit');
        $this->assertSame(2, $debits->count());
        $this->assertSame(1, $credits->count());
        $this->assertSame('550.0000', (string) $credits->first()->amount);
        $this->assertEqualsWithDelta(550.0, (float) $debits->sum('amount'), 0.0001);

        // Travel grouped 460, meals 90, each stamped with its chart account.
        $this->assertNotNull($debits->firstWhere('chart_account_id', $travel->id));
        $this->assertSame('460.0000', (string) $debits->firstWhere('chart_account_id', $travel->id)->amount);
        $this->assertSame('90.0000', (string) $debits->firstWhere('chart_account_id', $meals->id)->amount);

        // Trial balance nets to zero across all accounts.
        $trial = app(LedgerService::class)->getTrialBalance($tenant->id);
        $this->assertEqualsWithDelta(0.0, array_sum(array_column($trial, 'net')), 0.0001);

        $this->assertDatabaseHas('event_log', ['event_type' => 'gl.posting_created']);
    }

    public function test_unmapped_category_skips_posting_and_raises_alert(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->createUser($tenant, 'admin');
        $this->seedChart($tenant);
        $this->createRules($tenant, 'auto');

        // Category with NO gl_account_id mapping.
        $unmapped = ExpenseCategory::create([
            'tenant_id' => $tenant->id, 'name' => 'Misc', 'slug' => 'misc',
        ]);

        $reportId = $this->approveReport($tenant, $user, [
            ['description' => 'Mystery spend', 'amount' => '75.00', 'expense_date' => now()->toDateString(), 'category_id' => $unmapped->id],
        ]);

        $posting = GlPosting::forTenant($tenant->id)->forSource('expense_report', $reportId)->first();
        $this->assertSame('skipped', $posting->status);
        $this->assertStringContainsString('Mystery spend', (string) $posting->error);

        // No ledger rows were written for the skipped posting.
        $this->assertSame(0, LedgerEntry::forTenant($tenant->id)->count());

        $this->assertDatabaseHas('financial_alerts', [
            'tenant_id' => $tenant->id,
            'type' => 'gl_mapping_missing',
        ]);
        $this->assertDatabaseHas('event_log', ['event_type' => 'gl.posting_failed']);
    }

    public function test_no_rules_records_skipped_row(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        [$travel] = $this->seedChart($tenant);

        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id, 'name' => 'Travel', 'slug' => 'travel', 'gl_account_id' => $travel->id,
        ]);

        $reportId = $this->approveReport($tenant, $user, [
            ['description' => 'Train', 'amount' => '55.00', 'expense_date' => now()->toDateString(), 'category_id' => $category->id],
        ]);

        $posting = GlPosting::forTenant($tenant->id)->forSource('expense_report', $reportId)->first();
        $this->assertSame('skipped', $posting->status);
        $this->assertSame('posting_rules_not_configured', $posting->lines['skip_reason']);
        $this->assertSame(0, LedgerEntry::forTenant($tenant->id)->count());
    }

    public function test_draft_mode_defers_posting_until_explicit_post_endpoint(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $admin = $this->createUser($tenant, 'admin');
        [$travel] = $this->seedChart($tenant);
        $this->createRules($tenant, 'draft');

        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id, 'name' => 'Travel', 'slug' => 'travel', 'gl_account_id' => $travel->id,
        ]);

        $reportId = $this->approveReport($tenant, $user, [
            ['description' => 'Hotel', 'amount' => '300.00', 'expense_date' => now()->toDateString(), 'category_id' => $category->id],
        ]);

        $posting = GlPosting::forTenant($tenant->id)->forSource('expense_report', $reportId)->first();
        $this->assertSame('draft', $posting->status);
        $this->assertNull($posting->transaction_number);
        $this->assertNotEmpty($posting->lines['debit_lines']);

        // Nothing hit the ledger yet.
        $this->assertSame(0, LedgerEntry::forTenant($tenant->id)->count());

        // Member cannot execute the draft; admin can.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/accounting/postings/'.$posting->id.'/post')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/accounting/postings/'.$posting->id.'/post')
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $posting = $posting->fresh();
        $this->assertSame('posted', $posting->status);
        $this->assertNotNull($posting->transaction_number);
        $this->assertSame(2, LedgerEntry::forTenant($tenant->id)->count());

        // A second explicit post attempt conflicts.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/accounting/postings/'.$posting->id.'/post')
            ->assertStatus(409);
    }

    public function test_posting_is_idempotent_under_job_redispatch(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        [$travel] = $this->seedChart($tenant);
        $this->createRules($tenant, 'auto');

        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id, 'name' => 'Travel', 'slug' => 'travel', 'gl_account_id' => $travel->id,
        ]);

        $reportId = $this->approveReport($tenant, $user, [
            ['description' => 'Flight', 'amount' => '250.00', 'expense_date' => now()->toDateString(), 'category_id' => $category->id],
        ]);

        $ledgerCount = LedgerEntry::forTenant($tenant->id)->count();
        $this->assertSame(2, $ledgerCount);

        // Re-dispatch the job (retry / duplicate event) — nothing changes.
        (new PostToGeneralLedgerJob($tenant->id, 'expense_report', $reportId))
            ->handle(app(GlPostingService::class));
        (new PostToGeneralLedgerJob($tenant->id, 'expense_report', $reportId))
            ->handle(app(GlPostingService::class));

        $this->assertSame(1, GlPosting::forTenant($tenant->id)->forSource('expense_report', $reportId)->count());
        $this->assertSame($ledgerCount, LedgerEntry::forTenant($tenant->id)->count());
        $this->assertSame(1, LedgerEntry::forTenant($tenant->id)->where('side', 'credit')->count());
    }

    public function test_paid_bill_posts_to_ledger_in_auto_mode(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createUser($tenant, 'admin');
        [$travel, $meals] = $this->seedChart($tenant);
        $this->createRules($tenant, 'auto');

        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id, 'name' => 'Software', 'slug' => 'software', 'gl_account_id' => $meals->id,
        ]);

        $bill = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills', [
                'due_date' => now()->addDays(14)->toDateString(),
                'lines' => [[
                    'description' => 'SaaS licences',
                    'unit_price' => '200.00',
                    'quantity' => '2',
                    'category_id' => $category->id,
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/submit')
            ->assertOk();

        // mark-paid requires fresh step-up auth.
        $admin->update(['step_up_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/mark-paid', ['bank_reference' => 'TRF-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $posting = GlPosting::forTenant($tenant->id)->forSource('bill', $bill['id'])->first();
        $this->assertNotNull($posting, 'paying a bill should produce a gl_postings row');
        $this->assertSame('posted', $posting->status);
        $this->assertSame('400.0000', (string) $posting->amount);

        $entries = LedgerEntry::forTenant($tenant->id)
            ->where('transaction_id', $posting->transaction_number)
            ->get();
        $this->assertCount(2, $entries);
        $this->assertSame('400.0000', (string) $entries->firstWhere('side', 'credit')->amount);

        $trial = app(LedgerService::class)->getTrialBalance($tenant->id);
        $this->assertEqualsWithDelta(0.0, array_sum(array_column($trial, 'net')), 0.0001);
    }
}
