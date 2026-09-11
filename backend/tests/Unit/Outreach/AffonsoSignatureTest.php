<?php

declare(strict_types=1);

namespace Tests\Unit\Outreach;

use App\Http\Middleware\VerifyAffonsoSignature;
use PHPUnit\Framework\TestCase;

class AffonsoSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const BODY = '{"id":"evt_1","type":"affiliate.created","data":{"affiliateId":"aff_1"}}';

    public function test_a_fresh_signature_over_the_exact_body_verifies(): void
    {
        $now = 1_800_000_000;
        $header = VerifyAffonsoSignature::sign(self::BODY, self::SECRET, $now);

        $this->assertMatchesRegularExpression('/^t=1800000000,v1=[0-9a-f]{64}$/', $header);
        $this->assertTrue(VerifyAffonsoSignature::verify($header, self::BODY, self::SECRET, $now + 299));
        $this->assertTrue(VerifyAffonsoSignature::verify(strtoupper($header), self::BODY, self::SECRET, $now), 'hex case must not matter');
    }

    public function test_tampering_wrong_secret_and_stale_timestamps_fail(): void
    {
        $now = 1_800_000_000;
        $header = VerifyAffonsoSignature::sign(self::BODY, self::SECRET, $now);

        $this->assertFalse(VerifyAffonsoSignature::verify($header, self::BODY.' ', self::SECRET, $now));
        $this->assertFalse(VerifyAffonsoSignature::verify($header, self::BODY, 'other', $now));
        $this->assertFalse(VerifyAffonsoSignature::verify($header, self::BODY, self::SECRET, $now + 301));
        $this->assertFalse(VerifyAffonsoSignature::verify($header, self::BODY, self::SECRET, $now - 301));
    }

    public function test_malformed_headers_fail_and_multiple_signatures_need_one_match(): void
    {
        $now = 1_800_000_000;
        $valid = hash_hmac('sha256', $now.'.'.self::BODY, self::SECRET);

        $this->assertFalse(VerifyAffonsoSignature::verify('', self::BODY, self::SECRET, $now));
        $this->assertFalse(VerifyAffonsoSignature::verify('v1='.$valid, self::BODY, self::SECRET, $now));
        $this->assertFalse(VerifyAffonsoSignature::verify('t='.$now, self::BODY, self::SECRET, $now));
        $this->assertFalse(VerifyAffonsoSignature::verify('t=abc,v1='.$valid, self::BODY, self::SECRET, $now));
        $this->assertTrue(VerifyAffonsoSignature::verify("t={$now},v1=".str_repeat('0', 64).",v1={$valid}", self::BODY, self::SECRET, $now));
    }
}
