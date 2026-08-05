<?php

declare(strict_types=1);

namespace Tests\Unit\Spend;

use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpensePolicy;
use App\Models\ExpenseReport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Spend\ExpensePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpensePolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Policy Tenant',
            'slug' => 'policy-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role = 'member'): User
    {
        return User::create([
            'name' => 'Policy User',
            'email' => $role.'-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }

    private function category(Tenant $tenant, array $attrs = []): ExpenseCategory
    {
        return ExpenseCategory::create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Meals',
            'slug' => 'meals-'.Str::lower(Str::random(6)),
        ], $attrs));
    }

    private function report(Tenant $tenant, User $user): ExpenseReport
    {
        return ExpenseReport::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'report_number' => 'EXP-TEST-'.Str::upper(Str::random(6)),
            'title' => 'Policy matrix',
            'status' => 'draft',
            'currency' => 'USD',
            'total_amount' => 0,
        ]);
    }

    private function item(ExpenseReport $report, array $attrs = []): ExpenseItem
    {
        return ExpenseItem::create(array_merge([
            'expense_report_id' => $report->id,
            'tenant_id' => $report->tenant_id,
            'description' => 'Line item',
            'expense_date' => now()->toDateString(),
            'amount' => '10.00',
            'currency' => 'USD',
        ], $attrs));
    }

    public function test_flags_item_over_category_limit(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $category = $this->category($tenant, ['per_expense_limit' => '50.00']);
        $report = $this->report($tenant, $user);
        $item = $this->item($report, ['category_id' => $category->id, 'amount' => '75.00', 'has_receipt' => true]);

        $violations = app(ExpensePolicyService::class)->evaluate($report);

        $this->assertSame(['over_category_limit'], $item->fresh()->policy_flags);
        $this->assertSame(1, $report->fresh()->policy_violation_count);
        $this->assertCount(1, $violations);
        $this->assertSame($item->id, $violations[0]['item_id']);
    }

    public function test_no_flags_when_under_limits_with_receipt(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $category = $this->category($tenant, [
            'per_expense_limit' => '50.00',
            'requires_receipt_over' => '25.00',
        ]);
        $report = $this->report($tenant, $user);
        $item = $this->item($report, ['category_id' => $category->id, 'amount' => '30.00', 'has_receipt' => true]);

        $violations = app(ExpensePolicyService::class)->evaluate($report);

        $this->assertSame([], $item->fresh()->policy_flags);
        $this->assertSame(0, $report->fresh()->policy_violation_count);
        $this->assertSame([], $violations);
    }

    public function test_missing_receipt_via_category_threshold(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $category = $this->category($tenant, ['requires_receipt_over' => '25.00']);
        $report = $this->report($tenant, $user);
        $item = $this->item($report, ['category_id' => $category->id, 'amount' => '40.00']);

        app(ExpensePolicyService::class)->evaluate($report);

        $this->assertSame(['missing_receipt'], $item->fresh()->policy_flags);
    }

    public function test_missing_receipt_via_tenant_policy_threshold(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        ExpensePolicy::create([
            'tenant_id' => $tenant->id,
            'name' => 'Receipts over 25',
            'scope_type' => 'tenant',
            'receipt_required_over' => '25.00',
            'enabled' => true,
        ]);
        $report = $this->report($tenant, $user);
        // No category at all — the policy threshold alone triggers the flag.
        $item = $this->item($report, ['amount' => '60.00']);

        app(ExpensePolicyService::class)->evaluate($report);

        $this->assertContains('missing_receipt', $item->fresh()->policy_flags);
    }

    public function test_amount_at_threshold_does_not_require_receipt(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $category = $this->category($tenant, ['requires_receipt_over' => '25.00']);
        $report = $this->report($tenant, $user);
        $item = $this->item($report, ['category_id' => $category->id, 'amount' => '25.00']);

        app(ExpensePolicyService::class)->evaluate($report);

        $this->assertSame([], $item->fresh()->policy_flags);
    }

    public function test_category_not_allowed_flag(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $travel = $this->category($tenant, ['name' => 'Travel', 'slug' => 'travel']);
        $meals = $this->category($tenant, ['name' => 'Meals', 'slug' => 'meals']);
        ExpensePolicy::create([
            'tenant_id' => $tenant->id,
            'name' => 'Travel only',
            'scope_type' => 'tenant',
            'allowed_categories' => ['travel'],
            'enabled' => true,
        ]);
        $report = $this->report($tenant, $user);
        $ok = $this->item($report, ['category_id' => $travel->id, 'amount' => '10.00']);
        $bad = $this->item($report, ['category_id' => $meals->id, 'amount' => '10.00']);

        app(ExpensePolicyService::class)->evaluate($report);

        $this->assertSame([], $ok->fresh()->policy_flags);
        $this->assertSame(['category_not_allowed'], $bad->fresh()->policy_flags);
    }

    public function test_role_scoped_policy_only_applies_to_matching_role(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        ExpensePolicy::create([
            'tenant_id' => $tenant->id,
            'name' => 'Members need receipts over 10',
            'scope_type' => 'role',
            'scope_value' => 'member',
            'receipt_required_over' => '10.00',
            'enabled' => true,
        ]);

        $memberReport = $this->report($tenant, $member);
        $memberItem = $this->item($memberReport, ['amount' => '20.00']);

        $adminReport = $this->report($tenant, $admin);
        $adminItem = $this->item($adminReport, ['amount' => '20.00']);

        $service = app(ExpensePolicyService::class);
        $service->evaluate($memberReport);
        $service->evaluate($adminReport);

        $this->assertSame(['missing_receipt'], $memberItem->fresh()->policy_flags);
        $this->assertSame([], $adminItem->fresh()->policy_flags);
    }

    public function test_disabled_policy_is_ignored(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        ExpensePolicy::create([
            'tenant_id' => $tenant->id,
            'name' => 'Disabled',
            'scope_type' => 'tenant',
            'receipt_required_over' => '1.00',
            'enabled' => false,
        ]);
        $report = $this->report($tenant, $user);
        $item = $this->item($report, ['amount' => '500.00']);

        app(ExpensePolicyService::class)->evaluate($report);

        $this->assertSame([], $item->fresh()->policy_flags);
    }

    public function test_multiple_flags_count_individually(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $category = $this->category($tenant, [
            'per_expense_limit' => '50.00',
            'requires_receipt_over' => '25.00',
        ]);
        $report = $this->report($tenant, $user);
        $item = $this->item($report, ['category_id' => $category->id, 'amount' => '120.00']);

        $violations = app(ExpensePolicyService::class)->evaluate($report);

        $flags = $item->fresh()->policy_flags;
        $this->assertContains('over_category_limit', $flags);
        $this->assertContains('missing_receipt', $flags);
        $this->assertSame(2, $report->fresh()->policy_violation_count);
        $this->assertCount(1, $violations);
    }

    public function test_applicable_policies_filters_scope(): void
    {
        $tenant = $this->createTenant();
        $other = $this->createTenant();
        $member = $this->createUser($tenant, 'member');

        ExpensePolicy::create([
            'tenant_id' => $tenant->id, 'name' => 'Tenant-wide',
            'scope_type' => 'tenant', 'enabled' => true,
        ]);
        ExpensePolicy::create([
            'tenant_id' => $tenant->id, 'name' => 'Member scoped',
            'scope_type' => 'role', 'scope_value' => 'member', 'enabled' => true,
        ]);
        ExpensePolicy::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin scoped',
            'scope_type' => 'role', 'scope_value' => 'admin', 'enabled' => true,
        ]);
        ExpensePolicy::create([
            'tenant_id' => $other->id, 'name' => 'Other tenant',
            'scope_type' => 'tenant', 'enabled' => true,
        ]);

        $names = app(ExpensePolicyService::class)
            ->applicablePolicies($tenant->id, $member)
            ->pluck('name')
            ->all();

        sort($names);
        $this->assertSame(['Member scoped', 'Tenant-wide'], $names);
    }
}
