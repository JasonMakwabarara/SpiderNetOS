<?php

declare(strict_types=1);

namespace Tests\Unit\Mfa;

use App\Services\Auth\MfaService;
use PHPUnit\Framework\TestCase;

/**
 * Validates the self-contained TOTP against the official RFC 6238 SHA1 test
 * vectors (6-digit truncation of the published 8-digit values). No DB / boot.
 */
class MfaServiceTest extends TestCase
{
    /** base32("12345678901234567890") — the RFC 6238 SHA1 seed. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_rfc6238_sha1_vectors(): void
    {
        $svc = new MfaService;
        $this->assertSame('287082', $svc->codeAt(self::RFC_SECRET, 59));
        $this->assertSame('081804', $svc->codeAt(self::RFC_SECRET, 1111111109));
        $this->assertSame('005924', $svc->codeAt(self::RFC_SECRET, 1234567890));
        $this->assertSame('279037', $svc->codeAt(self::RFC_SECRET, 2000000000));
    }

    public function test_verify_accepts_current_code(): void
    {
        $svc = new MfaService;
        $secret = $svc->generateSecret();
        $t = 1_700_000_000;
        $this->assertTrue($svc->verify($secret, $svc->codeAt($secret, $t), $t));
    }

    public function test_verify_tolerates_one_period_drift(): void
    {
        $svc = new MfaService;
        $secret = $svc->generateSecret();
        $t = 1_700_000_000;
        // Code from the previous 30s window still accepted (window=1).
        $this->assertTrue($svc->verify($secret, $svc->codeAt($secret, $t - 30), $t));
        // Two windows away is rejected.
        $this->assertFalse($svc->verify($secret, $svc->codeAt($secret, $t - 90), $t));
    }

    public function test_verify_rejects_wrong_and_malformed(): void
    {
        $svc = new MfaService;
        $secret = $svc->generateSecret();
        $t = 1_700_000_000;
        $this->assertFalse($svc->verify($secret, '000000', $t));
        $this->assertFalse($svc->verify($secret, 'abcdef', $t));
        $this->assertFalse($svc->verify($secret, '12345', $t));
    }

    public function test_recovery_codes_shape(): void
    {
        $codes = (new MfaService)->generateRecoveryCodes(10);
        $this->assertCount(10, $codes);
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $c);
        }
        $this->assertSame($codes, array_unique($codes));
    }
}
