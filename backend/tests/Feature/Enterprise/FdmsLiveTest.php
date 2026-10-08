<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Models\FdmsDevice;
use App\Models\FdmsReceipt;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Enterprise\Fiscal\FdmsSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Services\FdmsSignerTest;

class FdmsLiveTest extends TestCase
{
    use RefreshDatabase;

    private string $keyPath;

    private string $certPath;

    protected function setUp(): void
    {
        $driver = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('FDMS live test requires PostgreSQL.');
        }

        parent::setUp();

        $this->keyPath = tempnam(sys_get_temp_dir(), 'fdms-key');
        $this->certPath = tempnam(sys_get_temp_dir(), 'fdms-cert');
        file_put_contents($this->keyPath, FdmsSignerTest::SPEC_KEY);
        file_put_contents($this->certPath, 'certificate is unused while HTTP is faked');
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);
        @unlink($this->certPath);
        parent::tearDown();
    }

    public function test_device_opens_day_signs_chained_receipts_and_closes_with_signed_counters(): void
    {
        $tenant = $this->tenant('Hammer');
        $other = $this->tenant('Other');
        $admin = $this->user($tenant);
        $this->actingAs($admin, 'sanctum');

        $base = 'https://fdms.test/Device/v1/321/';
        Http::fake([
            $base.'GetConfig' => Http::response([
                'qrUrl' => 'https://invoice.zimra.co.zw',
                'vatNumber' => '220000001',
                'applicableTaxes' => [
                    ['taxID' => 1, 'taxPercent' => 15, 'taxName' => 'VAT 15%'],
                    ['taxID' => 2, 'taxPercent' => 0, 'taxName' => 'Zero rated'],
                    ['taxID' => 3, 'taxName' => 'Exempt'],
                ],
            ]),
            $base.'GetStatus' => Http::sequence()
                ->push(['operationID' => 'o1', 'fiscalDayStatus' => 'FiscalDayClosed', 'lastFiscalDayNo' => 41, 'lastReceiptGlobalNo' => 100])
                ->push(['operationID' => 'o2', 'fiscalDayStatus' => 'FiscalDayClosed', 'lastFiscalDayNo' => 42]),
            $base.'OpenDay' => Http::response(['operationID' => 'o3', 'fiscalDayNo' => 42]),
            $base.'SubmitReceipt' => Http::sequence()
                ->push(['operationID' => 'o4', 'receiptID' => 9001, 'serverDate' => '2026-10-08T10:00:00'])
                ->push(['title' => 'Infrastructure error'], 500)
                ->push(['operationID' => 'o5', 'receiptID' => 9002, 'serverDate' => '2026-10-08T10:01:00'])
                ->push(['errorCode' => 'RCPT02', 'title' => 'Receipt structure invalid'], 422),
            $base.'CloseDay' => Http::response(['operationID' => 'o6']),
        ]);

        $this->postJson('/api/enterprise/fdms/day/open')->assertStatus(409);
        $this->configure($tenant->id);
        $this->actingAs($this->user($other), 'sanctum');
        $this->postJson('/api/enterprise/fdms/day/open')->assertStatus(409);
        Http::assertNothingSent();
        $this->actingAs($admin, 'sanctum');

        $first = $this->salesInvoice($tenant, 'INV-1', 2, 50, 15, 115);
        $second = $this->salesInvoice($tenant, 'INV-2', 1, 10, 0, 10);
        $third = $this->salesInvoice($tenant, 'INV-3', 1, 20, 0, 20);
        $draft = $this->salesInvoice($tenant, 'INV-4', 1, 5, 0, 5, 'draft');

        $this->postJson('/api/enterprise/fdms/config')->assertOk()->assertJsonPath('data.qr_url', 'https://invoice.zimra.co.zw');
        $this->postJson('/api/enterprise/sales-invoices/'.$first->id.'/fiscalise', ['money_type' => 'Cash'])->assertStatus(409);

        $device = $this->postJson('/api/enterprise/fdms/day/open')->assertOk()->json('data');
        $this->assertSame('FiscalDayOpened', $device['fiscal_day_status']);
        $this->assertSame(42, $device['fiscal_day_no']);
        $this->assertSame(100, $device['receipt_global_no']);

        $this->postJson('/api/enterprise/sales-invoices/'.$draft->id.'/fiscalise', ['money_type' => 'Cash'])->assertStatus(409);
        $this->postJson('/api/enterprise/sales-invoices/'.$first->id.'/fiscalise', ['money_type' => 'Gold'])->assertStatus(422);

        $one = $this->postJson('/api/enterprise/sales-invoices/'.$first->id.'/fiscalise', ['money_type' => 'Cash'])
            ->assertCreated()
            ->json('data');
        $this->assertSame('accepted', $one['status']);
        $this->assertSame(9001, $one['fdms_receipt_id']);
        $this->assertSame(101, $one['receipt_global_no']);
        $this->assertSame(1, $one['receipt_counter']);
        $this->assertEquals([['taxID' => 1, 'taxPercent' => 15, 'taxAmount' => 15, 'salesAmountWithTax' => 115]], $one['payload']['receiptTaxes']);
        $this->assertEquals(115, $one['payload']['receiptTotal']);
        $this->assertStringStartsWith('https://invoice.zimra.co.zw/0000000321', $one['qr_data']);
        $this->assertStringContainsString('0000000101', $one['qr_data']);
        $this->assertSigned($one, null);

        Http::assertSent(fn (Request $r) => $r->url() === $base.'SubmitReceipt'
            && $r->hasHeader('DeviceModelName', 'SpiderNetOS')
            && $r->hasHeader('DeviceModelVersionNo', '1.0')
            && $r['receipt']['invoiceNo'] === 'INV-1');

        $this->postJson('/api/enterprise/sales-invoices/'.$first->id.'/fiscalise', ['money_type' => 'Cash'])->assertStatus(409);

        $this->postJson('/api/enterprise/sales-invoices/'.$second->id.'/fiscalise', ['money_type' => 'Card'])->assertStatus(409);
        $held = FdmsReceipt::query()->where('invoice_id', $second->id)->firstOrFail();
        $this->assertSame('pending', $held->status);
        $this->assertSame(101, FdmsDevice::query()->firstOrFail()->receipt_global_no);
        $this->postJson('/api/enterprise/sales-invoices/'.$third->id.'/fiscalise', ['money_type' => 'Cash'])->assertStatus(409);
        $this->postJson('/api/enterprise/fdms/day/close')->assertStatus(409);

        $two = $this->postJson('/api/enterprise/sales-invoices/'.$second->id.'/fiscalise', ['money_type' => 'Card'])
            ->assertCreated()
            ->json('data');
        $this->assertSame($held->id, $two['id']);
        $this->assertSame(9002, $two['fdms_receipt_id']);
        $this->assertSame(102, $two['receipt_global_no']);
        $this->assertSigned($two, $one['receipt_hash']);
        $sentTwice = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->url() === $base.'SubmitReceipt' && $pair[0]['receipt']['invoiceNo'] === 'INV-2')
            ->map(fn ($pair) => $pair[0]->body())
            ->values();
        $this->assertCount(2, $sentTwice);
        $this->assertSame($sentTwice[0], $sentTwice[1]);

        $this->postJson('/api/enterprise/sales-invoices/'.$third->id.'/fiscalise', ['money_type' => 'Cash'])->assertStatus(409);
        $rejected = FdmsReceipt::query()->where('invoice_id', $third->id)->firstOrFail();
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('RCPT02', $rejected->error_code);
        $this->assertSame(102, FdmsDevice::query()->firstOrFail()->receipt_global_no);

        $closing = $this->postJson('/api/enterprise/fdms/day/close')->assertOk()->json('data');
        $this->assertSame('FiscalDayCloseInitiated', $closing['fiscal_day_status']);
        $close = collect(Http::recorded())->first(fn ($pair) => $pair[0]->url() === $base.'CloseDay')[0];
        $this->assertSame(42, $close['fiscalDayNo']);
        $this->assertSame(2, $close['receiptCounter']);
        $this->assertSame([
            ['SaleByTax', 'USD', 1, 115], ['SaleByTax', 'USD', 2, 10], ['SaleTaxByTax', 'USD', 1, 15],
            ['BalanceByMoneyType', 'USD', 'Cash', 115], ['BalanceByMoneyType', 'USD', 'Card', 10],
        ], array_map(fn ($c) => [
            $c['fiscalCounterType'], $c['fiscalCounterCurrency'],
            $c['fiscalCounterTaxID'] ?? $c['fiscalCounterMoneyType'], $c['fiscalCounterValue'],
        ], $close['fiscalDayCounters']));
        $canonical = (new FdmsSigner)->dayString(321, 42, substr($device['fiscal_day_opened_at'], 0, 10), $close['fiscalDayCounters']);
        $this->assertSame(base64_encode(hash('sha256', $canonical, true)), $close['fiscalDayDeviceSignature']['hash']);
        $this->assertSame(1, openssl_verify($canonical, base64_decode($close['fiscalDayDeviceSignature']['signature']), $this->publicKey(), OPENSSL_ALGO_SHA256));

        $status = $this->getJson('/api/enterprise/fdms/status')->assertOk()->json('data');
        $this->assertSame('FiscalDayClosed', $status['fiscal_day_status']);
        $this->assertSame(0, $status['receipt_counter']);
        $this->assertSame(102, $status['receipt_global_no']);

        $event = \DB::table('event_log')->where('aggregate_id', $one['id'])->value('payload');
        $payload = is_array($event) ? $event : json_decode((string) $event, true);
        $keys = array_keys($payload);
        sort($keys);
        $this->assertSame(['fdms_receipt_id', 'invoice_id', 'status'], $keys);
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function assertSigned(array $receipt, ?string $previousHash): void
    {
        $payload = $receipt['payload'];
        $canonical = (new FdmsSigner)->receiptString(321, $payload, $previousHash);
        $this->assertSame(base64_encode(hash('sha256', $canonical, true)), $payload['receiptDeviceSignature']['hash']);
        $this->assertSame($receipt['receipt_hash'], $payload['receiptDeviceSignature']['hash']);
        $this->assertSame(1, openssl_verify($canonical, base64_decode($payload['receiptDeviceSignature']['signature']), $this->publicKey(), OPENSSL_ALGO_SHA256));
    }

    private function publicKey(): string
    {
        return openssl_pkey_get_details(openssl_pkey_get_private(FdmsSignerTest::SPEC_KEY))['key'];
    }

    private function configure(string $tenantId): void
    {
        config([
            'fiscal.live' => true,
            'fiscal.fdms.base_url' => 'https://fdms.test',
            'fiscal.fdms.tenant_id' => $tenantId,
            'fiscal.fdms.device_id' => 321,
            'fiscal.fdms.model_name' => 'SpiderNetOS',
            'fiscal.fdms.model_version' => '1.0',
            'fiscal.fdms.cert_path' => $this->certPath,
            'fiscal.fdms.key_path' => $this->keyPath,
            'fiscal.fdms.key_passphrase' => null,
        ]);
    }

    private function salesInvoice(Tenant $tenant, string $number, float $quantity, float $price, float $rate, float $total, string $status = 'sent'): Invoice
    {
        $subtotal = $quantity * $price;
        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'invoice_number' => $number,
            'customer_name' => 'Walk-in',
            'subtotal' => $subtotal,
            'tax_amount' => $total - $subtotal,
            'discount_amount' => 0,
            'total_amount' => $total,
            'currency' => 'USD',
            'status' => $status,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        InvoiceLineItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Service '.$number,
            'quantity' => $quantity,
            'unit' => 'each',
            'unit_price' => $price,
            'tax_rate' => $rate,
            'total' => $total,
        ]);

        return $invoice;
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

    private function user(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Operator',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }
}
