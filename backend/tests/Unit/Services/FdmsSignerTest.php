<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Enterprise\Fiscal\FdmsSigner;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class FdmsSignerTest extends TestCase
{
    // Example key published in the ZIMRA FDMS spec, section 12.1.1. Illustration only.
    public const SPEC_KEY = "-----BEGIN EC PRIVATE KEY-----\n"
        ."MHcCAQEEIBXgREh8BvsXj0FjjcZ29EQiVjWGJuqHQp55+LlZd6waoAoGCCqGSM49\n"
        ."AwEHoUQDQgAE+79w72O6UYOJc9mfO8EjMEl9uysJaWJ0kVellIj46atl7FAG4NpY\n"
        ."VDe6t5pTSWlM6qCj5qKealESKalMnV32qQ==\n"
        ."-----END EC PRIVATE KEY-----\n";

    public function test_receipt_tax_block_matches_spec_examples(): void
    {
        $signer = new FdmsSigner;

        $this->assertSame('A0250000B0.000350000C15.0015000115000D15.0030000230000', $signer->taxesString([
            ['taxID' => 3, 'taxCode' => 'D', 'taxPercent' => 15, 'taxAmount' => 300, 'salesAmountWithTax' => 2300],
            ['taxID' => 1, 'taxCode' => 'A', 'taxAmount' => 0, 'salesAmountWithTax' => 2500],
            ['taxID' => 3, 'taxCode' => 'C', 'taxPercent' => 15, 'taxAmount' => 150, 'salesAmountWithTax' => 1150],
            ['taxID' => 2, 'taxCode' => 'B', 'taxPercent' => 0, 'taxAmount' => 0, 'salesAmountWithTax' => 3500],
        ]));
        $this->assertSame('07000.000100014.50535', $signer->taxesString([
            ['taxID' => 1, 'taxAmount' => 0, 'salesAmountWithTax' => 7],
            ['taxID' => 2, 'taxPercent' => 0, 'taxAmount' => 0, 'salesAmountWithTax' => 10],
            ['taxID' => 3, 'taxPercent' => 14.5, 'taxAmount' => 0.05, 'salesAmountWithTax' => 0.35],
        ]));
    }

    public function test_receipt_string_orders_fields_and_chains_previous_hash(): void
    {
        $receipt = [
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => 'zwl',
            'receiptGlobalNo' => 432,
            'receiptDate' => '2019-09-19T15:43:12',
            'receiptTotal' => 9450,
            'receiptTaxes' => [['taxID' => 1, 'taxCode' => 'A', 'taxAmount' => 0, 'salesAmountWithTax' => 2500]],
        ];
        $signer = new FdmsSigner;

        $this->assertSame('321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000', $signer->receiptString(321, $receipt, null));
        $this->assertSame('321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000prev=', $signer->receiptString(321, $receipt, 'prev='));
    }

    public function test_fiscal_day_string_reproduces_the_spec_hash(): void
    {
        $c = fn ($type, $cur, $pctOrMoney, $value) => $type === 'BalanceByMoneyType'
            ? ['fiscalCounterType' => $type, 'fiscalCounterCurrency' => $cur, 'fiscalCounterMoneyType' => $pctOrMoney, 'fiscalCounterValue' => $value]
            : ['fiscalCounterType' => $type, 'fiscalCounterCurrency' => $cur, 'fiscalCounterTaxPercent' => $pctOrMoney, 'fiscalCounterValue' => $value];
        // Spec 13.3.1 example, in the order and with the "USDL" currency as printed.
        $counters = [
            $c('SaleByTax', 'ZWL', null, 23000), $c('SaleByTax', 'ZWL', 0, 12000),
            $c('SaleByTax', 'USD', 14.5, 25), $c('SaleByTax', 'ZWL', 15, 12),
            $c('SaleTaxByTax', 'USD', 15, 2.5), $c('SaleTaxByTax', 'ZWL', 15, 2300),
            $c('BalanceByMoneyType', 'USDL', 'Cash', 37), $c('BalanceByMoneyType', 'ZWL', 'Cash', 20000),
            $c('BalanceByMoneyType', 'ZWL', 'Card', 15000),
        ];
        $canonical = (new FdmsSigner)->dayString(321, 84, '2019-09-23', $counters);

        $this->assertSame('OdT8lLI0JXhXl1XQgr64Zb1ltFDksFXThVxqM6O8xZE=', base64_encode(hash('sha256', $canonical, true)));
    }

    public function test_counters_sort_by_type_currency_tax_and_money_enum_and_drop_zeros(): void
    {
        $sorted = (new FdmsSigner)->sortCounters([
            ['fiscalCounterType' => 'BalanceByMoneyType', 'fiscalCounterCurrency' => 'USD', 'fiscalCounterMoneyType' => 'Card', 'fiscalCounterValue' => 1],
            ['fiscalCounterType' => 'BalanceByMoneyType', 'fiscalCounterCurrency' => 'USD', 'fiscalCounterMoneyType' => 'Cash', 'fiscalCounterValue' => 1],
            ['fiscalCounterType' => 'SaleTaxByTax', 'fiscalCounterCurrency' => 'USD', 'fiscalCounterTaxID' => 1, 'fiscalCounterValue' => 0],
            ['fiscalCounterType' => 'SaleByTax', 'fiscalCounterCurrency' => 'ZWG', 'fiscalCounterTaxID' => 1, 'fiscalCounterValue' => 1],
            ['fiscalCounterType' => 'SaleByTax', 'fiscalCounterCurrency' => 'USD', 'fiscalCounterTaxID' => 2, 'fiscalCounterValue' => 1],
            ['fiscalCounterType' => 'SaleByTax', 'fiscalCounterCurrency' => 'USD', 'fiscalCounterTaxID' => 1, 'fiscalCounterValue' => 1],
        ]);

        $this->assertSame(
            ['SaleByTax USD 1', 'SaleByTax USD 2', 'SaleByTax ZWG 1', 'BalanceByMoneyType USD Cash', 'BalanceByMoneyType USD Card'],
            array_map(fn ($c) => $c['fiscalCounterType'].' '.$c['fiscalCounterCurrency'].' '.($c['fiscalCounterTaxID'] ?? $c['fiscalCounterMoneyType']), $sorted),
        );
    }

    public function test_signature_is_der_ecdsa_over_the_canonical_string(): void
    {
        $signed = (new FdmsSigner)->sign('321FISCALINVOICEUSD1', self::SPEC_KEY);
        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::SPEC_KEY))['key'];

        $this->assertSame(base64_encode(hash('sha256', '321FISCALINVOICEUSD1', true)), $signed['hash']);
        $this->assertSame(1, openssl_verify('321FISCALINVOICEUSD1', base64_decode($signed['signature']), $public, OPENSSL_ALGO_SHA256));
        $this->assertSame("\x30", base64_decode($signed['signature'])[0]);
    }

    public function test_qr_value_pads_device_and_number_and_appends_md5_prefix(): void
    {
        $signature = base64_encode('signature-bytes');
        $qr = (new FdmsSigner)->qrData('https://invoice.zimra.co.zw/', 321, new DateTimeImmutable('2023-04-03 10:00:00'), 1112223331, $signature);

        $this->assertSame(
            'https://invoice.zimra.co.zw/000000032103042023'.'1112223331'.strtoupper(substr(md5('signature-bytes'), 0, 16)),
            $qr,
        );
    }
}
