<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use App\Exceptions\DomainException;

/**
 * Canonical strings, hashes and signatures from ZIMRA FDMS spec section 13,
 * and the receipt QR value from section 11. Fields are concatenated with no
 * separator; amounts are integer cents; tax percents carry two decimals.
 */
final class FdmsSigner
{
    public const COUNTER_TYPES = [
        'SaleByTax', 'SaleTaxByTax', 'CreditNoteByTax', 'CreditNoteTaxByTax',
        'DebitNoteByTax', 'DebitNoteTaxByTax', 'BalanceByMoneyType',
    ];

    public const MONEY_TYPES = ['Cash', 'Card', 'MobileWallet', 'Coupon', 'Credit', 'BankTransfer', 'Other'];

    public static function cents(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function percent(string|int|float|null $percent): string
    {
        return $percent === null ? '' : number_format((float) $percent, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $receipt  Receipt as sent to SubmitReceipt.
     */
    public function receiptString(int $deviceId, array $receipt, ?string $previousReceiptHash): string
    {
        return $deviceId
            .strtoupper((string) $receipt['receiptType'])
            .strtoupper((string) $receipt['receiptCurrency'])
            .$receipt['receiptGlobalNo']
            .$receipt['receiptDate']
            .self::cents($receipt['receiptTotal'])
            .$this->taxesString($receipt['receiptTaxes'])
            .($previousReceiptHash ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $taxes
     */
    public function taxesString(array $taxes): string
    {
        usort($taxes, fn (array $a, array $b) => [(int) $a['taxID'], (string) ($a['taxCode'] ?? '')]
            <=> [(int) $b['taxID'], (string) ($b['taxCode'] ?? '')]);

        $out = '';
        foreach ($taxes as $tax) {
            $out .= ($tax['taxCode'] ?? '')
                .self::percent($tax['taxPercent'] ?? null)
                .self::cents($tax['taxAmount'])
                .self::cents($tax['salesAmountWithTax']);
        }

        return $out;
    }

    /**
     * Counter type in enum order, then currency, then tax ID or money type in enum order.
     *
     * @param  list<array<string, mixed>>  $counters
     * @return list<array<string, mixed>>
     */
    public function sortCounters(array $counters): array
    {
        $counters = array_values(array_filter($counters, fn (array $c) => self::cents($c['fiscalCounterValue']) !== 0));
        $key = fn (array $c) => [
            array_search($c['fiscalCounterType'], self::COUNTER_TYPES, true),
            strtoupper((string) $c['fiscalCounterCurrency']),
            (int) ($c['fiscalCounterTaxID'] ?? 0),
            isset($c['fiscalCounterMoneyType']) ? array_search($c['fiscalCounterMoneyType'], self::MONEY_TYPES, true) : -1,
        ];
        usort($counters, fn (array $a, array $b) => $key($a) <=> $key($b));

        return $counters;
    }

    /**
     * @param  list<array<string, mixed>>  $sortedCounters  Already sorted and non-zero.
     */
    public function dayString(int $deviceId, int $fiscalDayNo, string $fiscalDayDate, array $sortedCounters): string
    {
        $out = $deviceId.$fiscalDayNo.$fiscalDayDate;
        foreach ($sortedCounters as $c) {
            $byMoney = $c['fiscalCounterType'] === 'BalanceByMoneyType';
            $out .= strtoupper((string) $c['fiscalCounterType'])
                .strtoupper((string) $c['fiscalCounterCurrency'])
                .($byMoney ? strtoupper((string) $c['fiscalCounterMoneyType']) : self::percent($c['fiscalCounterTaxPercent'] ?? null))
                .self::cents($c['fiscalCounterValue']);
        }

        return $out;
    }

    /**
     * @return array{hash: string, signature: string}
     */
    public function sign(string $canonical, string $privateKeyPem, ?string $passphrase = null): array
    {
        $key = openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');
        if ($key === false) {
            throw new DomainException('The FDMS device private key could not be read.');
        }
        if (! openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new DomainException('The FDMS device signature could not be produced.');
        }

        return [
            'hash' => base64_encode(hash('sha256', $canonical, true)),
            'signature' => base64_encode($signature),
        ];
    }

    public function qrData(string $qrUrl, int $deviceId, \DateTimeInterface $receiptDate, int $receiptGlobalNo, string $signatureBase64): string
    {
        return rtrim($qrUrl, '/').'/'
            .str_pad((string) $deviceId, 10, '0', STR_PAD_LEFT)
            .$receiptDate->format('dmY')
            .str_pad((string) $receiptGlobalNo, 10, '0', STR_PAD_LEFT)
            .strtoupper(substr(md5((string) base64_decode($signatureBase64, true)), 0, 16));
    }
}
