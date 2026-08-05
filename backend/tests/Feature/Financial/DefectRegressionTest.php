<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Financial\DocumentNumberService;
use App\Services\Financial\LedgerService;
use App\Services\Financial\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for the seven pre-existing Financial OS defects fixed
 * ahead of the spend-management suite (payment idempotency, invoice
 * mark-as-paid, route shadowing, racy numbering, ledger balance signs,
 * approval status vocabulary).
 */
class DefectRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Regression Tenant',
            'slug' => 'regression-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Regression User',
            'email' => 'regression@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }

    private function account(Tenant $tenant, string $type, string $balance = '0'): FinancialAccount
    {
        return FinancialAccount::create([
            'tenant_id' => $tenant->id,
            'name' => ucfirst($type).' '.Str::random(4),
            'type' => $type,
            'currency' => 'USD',
            'status' => 'active',
            'balance' => $balance,
        ]);
    }

    // D1: idempotency_key column exists, is persisted, and dedupes.
    public function test_payment_idempotency_key_dedupes(): void
    {
        $tenant = $this->createTenant();
        $service = app(PaymentService::class);

        $first = $service->recordPayment($tenant->id, '100.0000', 'bank_transfer', 'USD', null, null, null, null, 'key-123');
        $second = $service->recordPayment($tenant->id, '100.0000', 'bank_transfer', 'USD', null, null, null, null, 'key-123');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseHas('payments', ['id' => $first->id, 'idempotency_key' => 'key-123']);
        $this->assertSame(1, \App\Models\Payment::forTenant($tenant->id)->count());
    }

    // D2: recording a payment against an invoice marks it paid (was fatal).
    public function test_record_payment_marks_invoice_paid(): void
    {
        $tenant = $this->createTenant();

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-TEST-000001',
            'customer_name' => 'Acme',
            'subtotal' => '50.0000',
            'tax_amount' => '0',
            'discount_amount' => '0',
            'total_amount' => '50.0000',
            'currency' => 'USD',
            'status' => 'sent',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        app(PaymentService::class)->recordPayment($tenant->id, '50.0000', 'card', 'USD', $invoice->id);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
    }

    // D4: literal routes are reachable, not swallowed by /{id}.
    public function test_summary_routes_not_shadowed(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/invoices/summary')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/payments/summary')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/invoices/overdue')
            ->assertOk();
    }

    // D5: sequence allocation is monotonic per tenant and survives deletes
    // (COUNT(*)+1 reissued numbers after deletions).
    public function test_document_numbers_are_sequential_and_unique(): void
    {
        $tenant = $this->createTenant();
        $other = $this->createTenant();
        $numbers = app(DocumentNumberService::class);

        $a = null;
        $b = null;
        \DB::transaction(function () use (&$a, $numbers, $tenant) {
            $a = $numbers->next($tenant->id, 'invoice', 'INV');
        });
        \DB::transaction(function () use (&$b, $numbers, $tenant) {
            $b = $numbers->next($tenant->id, 'invoice', 'INV');
        });

        $this->assertStringEndsWith('-000001', $a);
        $this->assertStringEndsWith('-000002', $b);

        // Independent sequence per tenant and per type.
        \DB::transaction(function () use ($numbers, $other) {
            $this->assertStringEndsWith('-000001', $numbers->next($other->id, 'invoice', 'INV'));
        });
        \DB::transaction(function () use ($numbers, $tenant) {
            $this->assertStringEndsWith('-000001', $numbers->next($tenant->id, 'payment', 'PAY'));
        });
    }

    // D6: credit-normal accounts (liability/revenue) move the right way.
    public function test_ledger_balances_respect_account_type(): void
    {
        $tenant = $this->createTenant();
        $ledger = app(LedgerService::class);

        // Expense paid from cash: cash(asset) credited -> down; expense debited -> up.
        $cash = $this->account($tenant, 'asset', '1000.0000');
        $expense = $this->account($tenant, 'expense', '0');
        $ledger->createJournalEntry($tenant->id, $cash->id, $expense->id, '100.0000', 'USD', 'office supplies');

        $this->assertSame('900.0000', (string) $cash->fresh()->balance);
        $this->assertSame('100.0000', (string) $expense->fresh()->balance);

        // Revenue recognized into receivable: revenue credited -> UP (was down
        // under the old unconditional decrement), receivable debited -> up.
        $revenue = $this->account($tenant, 'revenue', '0');
        $receivable = $this->account($tenant, 'asset', '0');
        $ledger->createJournalEntry($tenant->id, $revenue->id, $receivable->id, '250.0000', 'USD', 'sale');

        $this->assertSame('250.0000', (string) $revenue->fresh()->balance);
        $this->assertSame('250.0000', (string) $receivable->fresh()->balance);

        // Liability credited -> up (borrowing cash increases both sides).
        $loan = $this->account($tenant, 'liability', '0');
        $cash2 = $this->account($tenant, 'asset', '0');
        $ledger->createJournalEntry($tenant->id, $loan->id, $cash2->id, '500.0000', 'USD', 'loan drawdown');

        $this->assertSame('500.0000', (string) $loan->fresh()->balance);
        $this->assertSame('500.0000', (string) $cash2->fresh()->balance);
    }

    // D6: compound entries stay balanced and post per-line chart accounts.
    public function test_compound_entry_posts_balanced_lines(): void
    {
        $tenant = $this->createTenant();
        $ledger = app(LedgerService::class);

        $clearing = $this->account($tenant, 'liability', '0');
        $travel = $this->account($tenant, 'expense', '0');
        $meals = $this->account($tenant, 'expense', '0');

        $ledger->postCompoundEntry(
            $tenant->id,
            [
                ['account_id' => $travel->id, 'amount' => '120.0000'],
                ['account_id' => $meals->id, 'amount' => '80.0000'],
            ],
            $clearing->id,
            'USD',
            'expense report ER-1',
            'expense_report',
            (string) Str::uuid(),
        );

        $this->assertSame('120.0000', (string) $travel->fresh()->balance);
        $this->assertSame('80.0000', (string) $meals->fresh()->balance);
        $this->assertSame('200.0000', (string) $clearing->fresh()->balance);

        // Trial balance nets to zero: debits equal credits.
        $trial = $ledger->getTrialBalance($tenant->id);
        $net = array_sum(array_column($trial, 'net'));
        $this->assertEqualsWithDelta(0.0, $net, 0.0001);
    }

    // D6: rebuild command recomputes drifted balances.
    public function test_rebuild_balances_command_fixes_drift(): void
    {
        $tenant = $this->createTenant();
        $ledger = app(LedgerService::class);

        $revenue = $this->account($tenant, 'revenue', '0');
        $receivable = $this->account($tenant, 'asset', '0');
        $ledger->createJournalEntry($tenant->id, $revenue->id, $receivable->id, '300.0000', 'USD', 'sale');

        // Simulate historical drift written by the old sign logic.
        $revenue->fresh()->update(['balance' => '-300.0000']);

        $this->artisan('ledger:rebuild-balances', ['--tenant' => $tenant->id])
            ->assertExitCode(0);

        $this->assertSame('300.0000', (string) $revenue->fresh()->balance);
    }

    // D7: engine and controller share one status vocabulary.
    public function test_approval_engine_writes_approved_status(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $engine = app(\App\Services\ApprovalEngine::class);

        $approval = $engine->createApproval(
            $tenant->id,
            $user->id,
            'manual',
            'test_resource',
            (string) Str::uuid(),
            'regression check',
        );

        $resolved = $engine->resolveApproval($approval['id'], $user->id, true, 'ok');

        $this->assertSame('approved', $resolved['status']);
        $this->assertDatabaseHas('approvals', ['id' => $approval['id'], 'status' => 'approved']);
    }
}
