<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Jobs\RunScheduledSpendExportsJob;
use App\Models\AccountingExport;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\SpendExportSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Financial\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Services\Spend\Accounting\AccountingExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountingExportTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Export Tenant',
            'slug' => 'export-'.Str::lower(Str::random(8)),
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

    /**
     * Seed one balanced compound journal: 300 travel + 100 meals debits
     * against a 400 payable credit, chart-stamped for export layouts.
     */
    private function seedLedger(Tenant $tenant): array
    {
        $travelChart = ChartOfAccount::create([
            'tenant_id' => $tenant->id, 'code' => '6100', 'name' => 'Travel Expense', 'type' => 'expense',
        ]);
        $mealsChart = ChartOfAccount::create([
            'tenant_id' => $tenant->id, 'code' => '6200', 'name' => 'Meals Expense', 'type' => 'expense',
        ]);

        $travel = FinancialAccount::create([
            'tenant_id' => $tenant->id, 'name' => 'Travel Expense', 'account_number' => '6100',
            'type' => 'expense', 'currency' => 'USD', 'status' => 'active',
        ]);
        $meals = FinancialAccount::create([
            'tenant_id' => $tenant->id, 'name' => 'Meals Expense', 'account_number' => '6200',
            'type' => 'expense', 'currency' => 'USD', 'status' => 'active',
        ]);
        $payable = FinancialAccount::create([
            'tenant_id' => $tenant->id, 'name' => 'Accounts Payable', 'account_number' => '2100',
            'type' => 'liability', 'currency' => 'USD', 'status' => 'active',
        ]);

        $transaction = app(LedgerService::class)->postCompoundEntry(
            $tenant->id,
            [
                ['account_id' => $travel->id, 'chart_account_id' => $travelChart->id, 'amount' => '300.0000', 'description' => 'Flights'],
                ['account_id' => $meals->id, 'chart_account_id' => $mealsChart->id, 'amount' => '100.0000', 'description' => 'Client dinner'],
            ],
            $payable->id,
            'USD',
            'Expense report EXP-TEST',
            'expense_report',
            (string) Str::uuid(),
        );

        return [$transaction, $travelChart, $mealsChart];
    }

    public function test_quickbooks_layout_headers_and_rows(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $admin = $this->createUser($tenant, 'admin');
        [$transaction] = $this->seedLedger($tenant);

        $export = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/accounting/exports', [
                'export_type' => 'quickbooks_csv',
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('generated', $export['status']);
        $this->assertSame(3, $export['row_count']);

        $csv = array_map('str_getcsv', array_filter(explode("\n", trim(
            Storage::disk('local')->get($export['file_path'])
        ))));

        $this->assertSame(
            ['JournalNo', 'JournalDate', 'AccountName', 'Debits', 'Credits', 'Description', 'Name', 'Currency'],
            $csv[0],
        );
        $this->assertCount(4, $csv); // header + 3 lines

        $rows = array_slice($csv, 1);

        // Every row carries the transaction number and today's date.
        foreach ($rows as $row) {
            $this->assertSame($transaction->transaction_number, $row[0]);
            $this->assertSame(now()->toDateString(), $row[1]);
            $this->assertSame('USD', $row[7]);
        }

        // Debit rows show amounts in Debits with empty Credits (and vice versa).
        $travelRow = collect($rows)->first(fn ($r) => $r[2] === 'Travel Expense');
        $this->assertSame('300.0000', $travelRow[3]);
        $this->assertSame('', $travelRow[4]);

        $creditRow = collect($rows)->first(fn ($r) => $r[4] !== '');
        $this->assertSame('400.0000', $creditRow[4]);
        $this->assertSame('', $creditRow[3]);

        $this->assertDatabaseHas('event_log', ['event_type' => 'accounting.export_generated']);
    }

    public function test_xero_layout_signed_amounts(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $admin = $this->createUser($tenant, 'admin');
        $this->seedLedger($tenant);

        $export = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/accounting/exports', [
                'export_type' => 'xero_csv',
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('generated', $export['status']);

        $csv = array_map('str_getcsv', array_filter(explode("\n", trim(
            Storage::disk('local')->get($export['file_path'])
        ))));

        $this->assertSame(['Narration', 'Date', 'Description', 'AccountCode', 'TaxRate', 'Amount'], $csv[0]);

        $rows = array_slice($csv, 1);
        $this->assertCount(3, $rows);

        // Debits positive, credit negative, and the journal sums to zero.
        $travelRow = collect($rows)->first(fn ($r) => $r[3] === '6100');
        $this->assertSame('300.0000', $travelRow[5]);

        $creditRow = collect($rows)->first(fn ($r) => str_starts_with($r[5], '-'));
        $this->assertSame('-400.0000', $creditRow[5]);
        $this->assertSame('2100', $creditRow[3]); // falls back to account_number

        $this->assertEqualsWithDelta(0.0, array_sum(array_map(fn ($r) => (float) $r[5], $rows)), 0.0001);
    }

    public function test_download_route_streams_csv_and_is_admin_gated(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $admin = $this->createUser($tenant, 'admin');
        $member = $this->createUser($tenant, 'member');
        $this->seedLedger($tenant);

        $export = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/accounting/exports', [
                'export_type' => 'generic_csv',
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/financial/accounting/exports/'.$export['id'].'/download')
            ->assertForbidden();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/financial/accounting/exports/'.$export['id'].'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $this->assertStringContainsString('transaction_id', $response->streamedContent());
    }

    public function test_member_cannot_create_export(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/accounting/exports', [
                'export_type' => 'generic_csv',
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_scheduled_export_job_generates_once_per_period(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $this->createUser($tenant, 'admin');
        $this->seedLedger($tenant);

        $schedule = SpendExportSchedule::create([
            'tenant_id' => $tenant->id,
            'export_type' => 'quickbooks_csv',
            'frequency' => 'weekly',
            'delivery' => 'notification',
            'enabled' => true,
        ]);

        (new RunScheduledSpendExportsJob)->handle(
            app(AccountingExportService::class),
            app(NotificationService::class),
        );

        $this->assertSame(1, AccountingExport::forTenant($tenant->id)->count());
        $this->assertNotNull($schedule->fresh()->last_run_at);

        $export = AccountingExport::forTenant($tenant->id)->first();
        $this->assertSame('generated', $export->status);
        // Previous full ISO week.
        $this->assertSame(now()->subWeek()->startOfWeek()->toDateString(), $export->period_start->toDateString());
        $this->assertSame(now()->subWeek()->endOfWeek()->toDateString(), $export->period_end->toDateString());

        // Same-week rerun is a no-op.
        (new RunScheduledSpendExportsJob)->handle(
            app(AccountingExportService::class),
            app(NotificationService::class),
        );

        $this->assertSame(1, AccountingExport::forTenant($tenant->id)->count());
    }
}
