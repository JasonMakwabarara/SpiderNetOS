<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $driver = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Credit note test requires PostgreSQL.');
        }

        parent::setUp();
    }

    public function test_credit_note_stays_on_the_canonical_invoice_and_reverses_a_posted_payable(): void
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
        $lineId = $order['lines'][0]['id'];
        $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/receipts', [
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => 2]],
        ])->assertCreated();

        $invoice = $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/invoice')->assertCreated()->json('data');
        $number = $invoice['invoice_number'];
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{6}$/', $number);

        $this->actingAs($otherAdmin, 'sanctum');
        $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/credit-notes', [
            'reason' => 'Wrong tenant',
            'lines' => [['description' => 'Widget A', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertNotFound();
        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/credit-notes', [
            'lines' => [],
        ])->assertStatus(422);

        $draft = $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/credit-notes', [
            'reason' => 'Damaged widgets',
            'lines' => [['description' => 'Widget A', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^CN-\d{8}-\d{6}$/', $draft['credit_note_number']);
        $this->assertSame('draft', $draft['status']);

        $issued = $this->postJson('/api/enterprise/credit-notes/'.$draft['id'].'/issue')->assertOk()->json('data');
        $this->assertSame('issued', $issued['status']);
        $this->assertSame(0, LedgerEntry::query()->where('reference_id', $draft['id'])->count());
        $this->postJson('/api/enterprise/credit-notes/'.$draft['id'].'/issue')->assertStatus(409);

        $created = \DB::table('event_log')->where('aggregate_id', $draft['id'])->where('event_type', 'enterprise.credit_note.issued')->value('payload');
        $payload = is_array($created) ? $created : json_decode((string) $created, true);
        $keys = array_keys($payload);
        sort($keys);
        $this->assertSame(['credit_note_id', 'invoice_id', 'status'], $keys);
        $this->assertSame('issued', $payload['status']);
        $this->assertStringNotContainsString('Damaged widgets', json_encode($payload));

        $this->actingAs($otherAdmin, 'sanctum');
        $this->postJson('/api/enterprise/credit-notes/'.$draft['id'].'/issue')->assertNotFound();
        $this->getJson('/api/enterprise/credit-notes/'.$draft['id'])->assertNotFound();
        $this->actingAs($admin, 'sanctum');

        $over = $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/credit-notes', [
            'lines' => [['description' => 'Widget A', 'quantity' => 2, 'unit_price' => 10]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/credit-notes/'.$over['id'].'/issue')->assertStatus(409);
        $this->assertSame('draft', \DB::table('credit_notes')->where('id', $over['id'])->value('status'));

        $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/match')->assertOk();
        $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/post')->assertCreated();

        $reversal = $this->postJson('/api/enterprise/supplier-invoices/'.$invoice['id'].'/credit-notes', [
            'lines' => [['description' => 'Widget A', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/credit-notes/'.$reversal['id'].'/issue')->assertOk();
        $entries = LedgerEntry::query()->where('reference_id', $reversal['id'])->get();
        $this->assertCount(2, $entries);
        $this->assertEqualsCanonicalizing(['debit', 'credit'], $entries->pluck('side')->all());
        $this->assertEquals(10, (float) $entries->first()->amount);

        $fresh = Invoice::find($invoice['id']);
        $this->assertSame($number, $fresh->invoice_number);
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->fiscal_status ?? null);
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
