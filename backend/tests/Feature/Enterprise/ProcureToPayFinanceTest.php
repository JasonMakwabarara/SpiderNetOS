<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcureToPayFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $driver = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Procure-to-pay finance test requires PostgreSQL.');
        }

        parent::setUp();
    }

    public function test_received_purchase_order_matches_posts_settles_and_fiscalises_in_sandbox(): void
    {
        $tenant = $this->tenant('Alpha');
        $other = $this->tenant('Beta');
        $admin = $this->user($tenant, 'admin');
        $otherAdmin = $this->user($other, 'admin');
        $this->actingAs($admin, 'sanctum');

        $vendor = $this->postJson('/api/enterprise/vendors', ['name' => 'Widget Supply'])->assertCreated()->json('data');
        $requisition = $this->postJson('/api/enterprise/requisitions', [
            'title' => 'Widgets',
            'lines' => [['description' => 'Widget A', 'quantity' => 2, 'unit_price' => 10]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/requisitions/'.$requisition['id'].'/submit')->assertOk();
        $approvalId = \DB::table('approvals')->where('resource_id', $requisition['id'])->value('id');
        $this->postJson('/api/approvals/'.$approvalId.'/approve', ['reason' => 'Buy them'])->assertOk();

        $order = $this->postJson('/api/enterprise/purchase-orders', [
            'vendor_id' => $vendor['id'],
            'requisition_id' => $requisition['id'],
            'currency' => 'USD',
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/issue')->assertOk();
        $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/invoice')->assertStatus(409);

        $lineId = $order['lines'][0]['id'];
        $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/receipts', [
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => 2]],
        ])->assertCreated();

        $this->actingAs($otherAdmin, 'sanctum');
        $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/invoice')->assertNotFound();
        $this->actingAs($admin, 'sanctum');

        $invoice = $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/invoice')
            ->assertCreated()
            ->json('data');
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{6}$/', $invoice['invoice_number']);
        $this->assertSame($order['id'], $invoice['purchase_order_id']);
        $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/invoice')->assertStatus(409);

        $linked = \DB::table('event_log')->where('aggregate_id', $invoice['id'])->where('event_type', 'enterprise.invoice.linked')->value('payload');
        $linkedPayload = is_array($linked) ? $linked : json_decode((string) $linked, true);
        $keys = array_keys($linkedPayload);
        sort($keys);
        $this->assertSame(['invoice_id', 'purchase_order_id', 'status', 'vendor_id'], $keys);
        $this->assertStringNotContainsString('Widget Supply', json_encode($linkedPayload));

        InvoiceLineItem::query()->where('invoice_id', $invoice['id'])->update(['quantity' => 9]);
        $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/match')
            ->assertOk()
            ->assertJsonPath('data.status', 'exception');
        $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/post')->assertStatus(409);

        InvoiceLineItem::query()->where('invoice_id', $invoice['id'])->update(['quantity' => 2]);
        $match = $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/match')
            ->assertOk()
            ->json('data');
        $this->assertSame('matched', $match['status']);
        $this->assertSame('2.0000', $match['lines'][0]['ordered_quantity']);
        $this->assertSame('2.0000', $match['lines'][0]['received_quantity']);
        $this->assertSame('2.0000', $match['lines'][0]['invoiced_quantity']);

        $posting = $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/post')->assertCreated()->json('data');
        $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/post')->assertStatus(409);
        $entries = LedgerEntry::query()->where('reference_id', $invoice['id'])->get();
        $this->assertCount(2, $entries);
        $this->assertEqualsCanonicalizing(['debit', 'credit'], $entries->pluck('side')->all());
        $this->assertEquals(20, (float) $entries->firstWhere('side', 'debit')->amount);
        $this->assertEquals(20, (float) $entries->firstWhere('side', 'credit')->amount);

        $posted = \DB::table('event_log')->where('aggregate_id', $posting['id'])->value('payload');
        $postedPayload = is_array($posted) ? $posted : json_decode((string) $posted, true);
        $postedKeys = array_keys($postedPayload);
        sort($postedKeys);
        $this->assertSame(['invoice_id', 'posting_id', 'purchase_order_id', 'status'], $postedKeys);

        $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/fiscalise', ['environment' => 'live'])
            ->assertStatus(409);
        $this->assertSame(0, \DB::table('fiscal_submissions')->count());
        $fiscal = $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/fiscalise')
            ->assertCreated()
            ->json('data');
        $this->assertSame('sandbox', $fiscal['environment']);
        $this->assertSame('accepted', $fiscal['status']);
        $this->assertNull(Invoice::find($invoice['id'])->fiscal_status ?? null);
        $fiscalEvent = \DB::table('event_log')->where('aggregate_id', $fiscal['id'])->value('payload');
        $fiscalPayload = is_array($fiscalEvent) ? $fiscalEvent : json_decode((string) $fiscalEvent, true);
        $fiscalKeys = array_keys($fiscalPayload);
        sort($fiscalKeys);
        $this->assertSame(['environment', 'fiscal_submission_id', 'invoice_id', 'status'], $fiscalKeys);

        $cashbook = $this->postJson('/api/enterprise/cashbooks', ['name' => 'Petty', 'currency' => 'USD'])
            ->assertCreated()
            ->json('data');
        $this->actingAs($otherAdmin, 'sanctum');
        $foreignBook = $this->postJson('/api/enterprise/cashbooks', ['name' => 'Other', 'currency' => 'USD'])
            ->assertCreated()
            ->json('data');
        $this->postJson('/api/enterprise/cashbooks/'.$foreignBook['id'].'/movements', [
            'type' => 'payment',
            'amount' => 20,
            'currency' => 'USD',
            'movement_date' => now()->toDateString(),
            'invoice_id' => $invoice['id'],
        ])->assertNotFound();
        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/enterprise/cashbooks/'.$cashbook['id'].'/movements', [
            'type' => 'payment',
            'amount' => 10,
            'currency' => 'USD',
            'movement_date' => now()->toDateString(),
            'invoice_id' => $invoice['id'],
        ])->assertStatus(409);
        $this->assertSame('draft', Invoice::find($invoice['id'])->status);

        $movement = $this->postJson('/api/enterprise/cashbooks/'.$cashbook['id'].'/movements', [
            'type' => 'payment',
            'amount' => 20,
            'currency' => 'USD',
            'movement_date' => now()->toDateString(),
            'invoice_id' => $invoice['id'],
        ])->assertCreated()->json('data');
        $this->assertSame('paid', Invoice::find($invoice['id'])->status);
        $cashEvent = \DB::table('event_log')->where('aggregate_id', $movement['id'])->value('payload');
        $cashPayload = is_array($cashEvent) ? $cashEvent : json_decode((string) $cashEvent, true);
        $cashKeys = array_keys($cashPayload);
        sort($cashKeys);
        $this->assertSame(['cash_movement_id', 'cashbook_id', 'invoice_id', 'type'], $cashKeys);
        $this->assertSame(4, LedgerEntry::query()->where('reference_id', $invoice['id'])->count());
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function user(Tenant $tenant, string $role): User
    {
        return User::create([
            'name' => 'Operator',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }
}
