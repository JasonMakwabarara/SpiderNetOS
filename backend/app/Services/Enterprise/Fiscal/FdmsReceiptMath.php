<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use App\Exceptions\DomainException;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;

/**
 * Receipt lines, tax table and total from FDMS spec section 4.7. Amounts are
 * computed unsigned in cents and signed at the end: positive for a fiscal
 * invoice, negative for a credit note (Discount lines take the opposite sign).
 */
final class FdmsReceiptMath
{
    public static function unitPrice(float $price, int|float|null $percent, bool $inclusive): float
    {
        return $inclusive
            ? round($price * (1 + ((float) ($percent ?? 0)) / 100), 6)
            : round($price, 6);
    }

    public static function lineCents(float $price, float $quantity, int|float|null $percent, bool $inclusive): int
    {
        return FdmsSigner::cents(round(self::unitPrice($price, $percent, $inclusive) * round($quantity, 6), 2));
    }

    /**
     * Split $amount cents across $weights by largest remainder, so the shares sum exactly.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights
     * @return array<TKey, int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($amount === 0 || $total <= 0) {
            return array_map(fn () => 0, $weights);
        }

        $shares = [];
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $shares[$key] = intdiv($amount * $weight, $total);
            $remainders[$key] = ($amount * $weight) % $total;
        }
        arsort($remainders);
        $left = $amount - array_sum($shares);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $shares[$key]++;
            $left--;
        }

        return $shares;
    }

    /**
     * The invoice discount as tax-inclusive cents per line id; empty when there is none.
     * The discount is what reconciles the lines with the invoice total, so the
     * receipt total always equals what the customer was billed.
     *
     * @return array<string, int>
     */
    public static function invoiceDiscounts(Invoice $invoice): array
    {
        if ((float) $invoice->discount_amount <= 0) {
            return [];
        }

        $weights = [];
        foreach ($invoice->lineItems as $item) {
            /** @var InvoiceLineItem $item */
            $weights[(string) $item->id] = self::lineCents((float) $item->unit_price, (float) $item->quantity, (float) $item->tax_rate, true);
        }
        $discount = array_sum($weights) - FdmsSigner::cents($invoice->total_amount);
        // Each line's tax may round by a cent, so allow one cent of drift per line.
        if ($discount <= 0 || abs($discount - FdmsSigner::cents($invoice->discount_amount)) > count($weights)) {
            throw new DomainException('The invoice discount does not reconcile with its lines and total.');
        }

        return self::allocate($discount, $weights);
    }

    /**
     * @param  list<array{name: string, price: float, quantity: float, tax: array<string, mixed>, hs_code: ?string, discount_cents: int}>  $items
     * @param  int  $sign  1 for a fiscal invoice, -1 for a credit note.
     * @return array{lines: list<array<string, mixed>>, taxes: list<array<string, mixed>>, total_cents: int}
     */
    public static function body(array $items, bool $inclusive, int $sign): array
    {
        $lines = [];
        $groups = [];
        $no = 0;
        foreach ($items as $item) {
            $taxId = (int) $item['tax']['taxID'];
            $percent = $item['tax']['taxPercent'] ?? null;
            $unit = self::unitPrice($item['price'], $percent, $inclusive);
            $quantity = round($item['quantity'], 6);
            $cents = FdmsSigner::cents(round($unit * $quantity, 2));

            $line = [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => ++$no,
                'receiptLineName' => mb_substr($item['name'], 0, 200),
                'receiptLinePrice' => $sign * $unit,
                'receiptLineQuantity' => $quantity,
                'receiptLineTotal' => $sign * $cents / 100,
                'taxID' => $taxId,
            ];
            if ($percent !== null) {
                $line['taxPercent'] = (float) $percent;
            }
            if (! empty($item['hs_code'])) {
                $line['receiptLineHSCode'] = (string) $item['hs_code'];
            }
            $lines[] = $line;

            $groups[$taxId] ??= ['taxID' => $taxId, 'taxPercent' => $percent, 'cents' => 0, 'discount' => 0, 'hs_code' => null];
            $groups[$taxId]['cents'] += $cents;
            $groups[$taxId]['discount'] += $item['discount_cents'];
            $groups[$taxId]['hs_code'] ??= $line['receiptLineHSCode'] ?? null;
        }

        ksort($groups);
        foreach ($groups as $group) {
            if ($group['discount'] <= 0) {
                continue;
            }
            $line = [
                'receiptLineType' => 'Discount',
                'receiptLineNo' => ++$no,
                'receiptLineName' => 'Discount',
                'receiptLinePrice' => -$sign * $group['discount'] / 100,
                'receiptLineQuantity' => 1,
                'receiptLineTotal' => -$sign * $group['discount'] / 100,
                'taxID' => $group['taxID'],
            ];
            if ($group['taxPercent'] !== null) {
                $line['taxPercent'] = (float) $group['taxPercent'];
            }
            if ($group['hs_code'] !== null) {
                $line['receiptLineHSCode'] = $group['hs_code'];
            }
            $lines[] = $line;
        }

        $taxes = [];
        $total = 0;
        foreach ($groups as $group) {
            $net = $group['cents'] - $group['discount'];
            $percent = $group['taxPercent'] === null ? null : (float) $group['taxPercent'];
            if ($percent === null) {
                $taxCents = 0;
            } elseif ($inclusive) {
                $taxCents = (int) round($net * $percent / (100 + $percent));
            } else {
                $taxCents = (int) round($net * $percent / 100);
            }
            $withTax = $inclusive ? $net : $net + $taxCents;

            $tax = ['taxID' => $group['taxID']];
            if ($percent !== null) {
                $tax['taxPercent'] = $percent;
            }
            $tax['taxAmount'] = $sign * $taxCents / 100;
            $tax['salesAmountWithTax'] = $sign * $withTax / 100;
            $taxes[] = $tax;
            $total += $withTax;
        }

        return ['lines' => $lines, 'taxes' => $taxes, 'total_cents' => $sign * $total];
    }
}
