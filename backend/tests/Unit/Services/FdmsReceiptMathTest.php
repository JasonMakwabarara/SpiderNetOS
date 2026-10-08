<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Enterprise\Fiscal\FdmsReceiptMath;
use PHPUnit\Framework\TestCase;

class FdmsReceiptMathTest extends TestCase
{
    public function test_allocation_sums_exactly_and_gives_remainders_to_largest_fractions(): void
    {
        $this->assertSame(['a' => 34, 'b' => 33, 'c' => 33], FdmsReceiptMath::allocate(100, ['a' => 1, 'b' => 1, 'c' => 1]));
        $this->assertSame([0 => 1150, 1 => 200], FdmsReceiptMath::allocate(1350, [11500, 2000]));
        $this->assertSame(['x' => 0, 'y' => 0], FdmsReceiptMath::allocate(0, ['x' => 5, 'y' => 7]));
        $this->assertSame(1001, array_sum(FdmsReceiptMath::allocate(1001, [333, 333, 334, 7])));
    }

    public function test_credit_note_body_is_negative_with_positive_discount_lines(): void
    {
        $body = FdmsReceiptMath::body([[
            'name' => 'Cement', 'price' => 50.0, 'quantity' => 1.0,
            'tax' => ['taxID' => 1, 'taxPercent' => 15], 'hs_code' => null, 'discount_cents' => 575,
        ]], true, -1);

        $this->assertSame(-5175, $body['total_cents']);
        $this->assertEquals([-57.5, 5.75], array_column($body['lines'], 'receiptLineTotal'));
        $this->assertEquals([['taxID' => 1, 'taxPercent' => 15, 'taxAmount' => -6.75, 'salesAmountWithTax' => -51.75]], $body['taxes']);
    }
}
