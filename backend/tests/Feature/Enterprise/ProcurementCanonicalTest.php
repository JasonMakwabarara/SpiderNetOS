<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Financial\DocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcurementCanonicalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $driver = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Procurement canonical test requires PostgreSQL.');
        }

        parent::setUp();
    }

    public function test_requisition_approves_for_procurement_without_touching_invoices(): void
    {
        $tenant = $this->tenant('Alpha');
        $other = $this->tenant('Beta');
        $admin = $this->user($tenant, 'admin');
        $otherAdmin = $this->user($other, 'admin');

        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/enterprise/requisitions', [
            'title' => 'Laptops',
            'lines' => [],
        ])->assertStatus(422);

        $draft = $this->postJson('/api/enterprise/requisitions', [
            'title' => 'Laptops',
            'lines' => [
                ['description' => 'Notebook', 'quantity' => 2, 'unit_price' => 10],
            ],
        ])->assertCreated()->json('data');
        $this->assertSame('REQ-000001', $draft['requisition_number']);
        $this->assertSame('draft', $draft['status']);

        $submitted = $this->postJson('/api/enterprise/requisitions/'.$draft['id'].'/submit')
            ->assertOk()
            ->json('data');
        $this->assertSame('submitted', $submitted['status']);
        $this->postJson('/api/enterprise/requisitions/'.$draft['id'].'/submit')->assertStatus(409);

        $approvalId = \DB::table('approvals')
            ->where('tenant_id', $tenant->id)
            ->where('resource_type', 'requisition')
            ->where('resource_id', $draft['id'])
            ->value('id');
        $this->postJson('/api/approvals/'.$approvalId.'/approve', ['reason' => 'Buy them'])
            ->assertOk()
            ->assertJsonPath('action.status', 'done');
        $this->assertSame('approved', Requisition::find($draft['id'])->status);
        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, Invoice::query()->count());

        $created = \DB::table('event_log')
            ->where('aggregate_id', $draft['id'])
            ->where('event_type', 'enterprise.requisition.approved')
            ->value('payload');
        $payload = is_array($created) ? $created : json_decode((string) $created, true);
        $this->assertEqualsCanonicalizing(['requisition_id', 'status'], array_keys($payload));
        $this->assertStringNotContainsString('Laptops', json_encode($payload));

        $vendor = $this->postJson('/api/enterprise/vendors', ['name' => 'Stationery Co'])->assertCreated()->json('data');
        $this->assertNotNull(Vendor::forTenant($tenant->id)->find($vendor['id']));

        $this->postJson('/api/enterprise/purchase-orders', [
            'vendor_id' => $vendor['id'],
            'requisition_id' => $draft['id'],
            'currency' => 'usd',
            'amount' => 20,
        ])->assertCreated()
            ->assertJsonPath('data.po_number', 'PO-000001')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', 'draft');

        $second = $this->postJson('/api/enterprise/requisitions', [
            'title' => 'Paper',
            'lines' => [
                ['description' => 'Reams', 'quantity' => 1, 'unit_price' => 5],
            ],
        ])->assertCreated()->json('data');
        $this->assertSame('REQ-000002', $second['requisition_number']);
        $this->postJson('/api/enterprise/purchase-orders', [
            'vendor_id' => $vendor['id'],
            'requisition_id' => $second['id'],
            'currency' => 'USD',
            'amount' => 5,
        ])->assertStatus(409);

        $order = PurchaseOrder::query()->with('lines')->firstOrFail();
        $this->assertCount(1, $order->lines);
        $lineId = $order->lines->first()->id;
        $this->postJson('/api/enterprise/purchase-orders/'.$order->id.'/issue')
            ->assertOk()
            ->assertJsonPath('data.status', 'issued');
        $this->postJson('/api/enterprise/purchase-orders/'.$order->id.'/issue')->assertStatus(409);

        $order->refresh();
        $this->assertSame('issued', $order->status);
        $this->assertSame(0, \DB::table('goods_receipts')->where('purchase_order_id', $order->id)->count());
        $this->actingAs($otherAdmin, 'sanctum');
        $this->assertNotContains(
            $order->id,
            collect($this->getJson('/api/enterprise/purchase-orders')->assertOk()->json('data'))->pluck('id')->all(),
        );
        $this->postJson('/api/enterprise/purchase-orders/'.$order->id.'/receipts', [
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => 2]],
        ])->assertNotFound();
        $order->refresh();
        $this->assertSame('issued', $order->status);
        $this->assertSame(0, \DB::table('goods_receipts')->where('purchase_order_id', $order->id)->count());
        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/enterprise/purchase-orders/'.$order->id.'/receipts', [
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => 3]],
        ])->assertStatus(409);
        $this->postJson('/api/enterprise/purchase-orders/'.$order->id.'/receipts', [
            'lines' => [['purchase_order_line_id' => (string) Str::uuid(), 'quantity' => 1]],
        ])->assertNotFound();
        $receipt = $this->postJson('/api/enterprise/purchase-orders/'.$order->id.'/receipts', [
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => 2]],
        ])->assertCreated()->json('data');
        $this->assertSame('received', $order->refresh()->status);
        $recorded = \DB::table('event_log')->where('aggregate_id', $receipt['id'])->value('payload');
        $recordedPayload = is_array($recorded) ? $recorded : json_decode((string) $recorded, true);
        $keys = array_keys($recordedPayload);
        sort($keys);
        $this->assertSame(['goods_receipt_id', 'purchase_order_id', 'status'], $keys);
        $this->assertSame($receipt['id'], $recordedPayload['goods_receipt_id']);
        $this->assertSame($order->id, $recordedPayload['purchase_order_id']);
        $this->assertSame('received', $recordedPayload['status']);
        $shown = $this->getJson('/api/enterprise/purchase-orders/'.$order->id)->assertOk()->json('data');
        $this->assertCount(1, $shown['receipts']);
        $this->assertSame($lineId, $shown['receipts'][0]['lines'][0]['purchase_order_line_id']);
        $this->assertEquals(2, (float) $shown['receipts'][0]['lines'][0]['quantity']);
        $this->postJson('/api/enterprise/purchase-orders/'.$order->id.'/receipts', [
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => 1]],
        ])->assertStatus(409);
        $this->assertSame(0, Invoice::query()->count());

        $archived = $this->postJson('/api/enterprise/vendors', ['name' => 'Closed Shop'])->assertCreated()->json('data');
        Vendor::query()->whereKey($archived['id'])->update(['status' => 'archived']);
        $this->postJson('/api/enterprise/purchase-orders', [
            'vendor_id' => $archived['id'],
            'currency' => 'USD',
            'amount' => 1,
        ])->assertStatus(409);

        $this->actingAs($otherAdmin, 'sanctum');
        $foreignVendor = $this->postJson('/api/enterprise/vendors', ['name' => 'Other Stationery'])->assertCreated()->json('data');
        $foreignRequisition = $this->postJson('/api/enterprise/requisitions', [
            'title' => 'Foreign',
            'lines' => [['description' => 'Hidden', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertCreated()->json('data');
        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/enterprise/purchase-orders', [
            'vendor_id' => $foreignVendor['id'],
            'requisition_id' => $draft['id'],
            'currency' => 'USD',
            'amount' => 20,
        ])->assertNotFound();
        $this->postJson('/api/enterprise/purchase-orders', [
            'vendor_id' => $vendor['id'],
            'requisition_id' => $foreignRequisition['id'],
            'currency' => 'USD',
            'amount' => 1,
        ])->assertNotFound();

        $this->actingAs($otherAdmin, 'sanctum');
        $this->getJson('/api/enterprise/requisitions/'.$draft['id'])->assertNotFound();
        $this->postJson('/api/enterprise/purchase-orders', [
            'vendor_id' => $vendor['id'],
            'currency' => 'USD',
            'amount' => 1,
        ])->assertNotFound();

        $invoiceNumber = app(DocumentNumberService::class)->next($tenant->id, 'invoice', 'INV');
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{6}$/', $invoiceNumber);
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
