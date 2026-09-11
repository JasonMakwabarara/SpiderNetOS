<?php

declare(strict_types=1);

namespace Tests\Unit\Outreach;

use App\Services\Outreach\Enrichment\EmailValidator;
use PHPUnit\Framework\TestCase;

class EmailValidatorTest extends TestCase
{
    private function validator(bool $domainOk = true): EmailValidator
    {
        // Injected resolver: the suite never touches DNS.
        return new EmailValidator(fn (string $domain) => $domainOk);
    }

    public function test_accepts_a_normal_business_address_and_normalises_case(): void
    {
        $result = $this->validator()->check('  Hello@Creator-Studio.COM ');

        $this->assertTrue($result['ok']);
        $this->assertSame('hello@creator-studio.com', $result['email']);
        $this->assertNull($result['reason']);
    }

    public function test_rejects_bad_syntax_role_and_disposable_addresses(): void
    {
        $v = $this->validator();

        $this->assertSame('invalid_syntax', $v->check('not-an-email')['reason']);
        $this->assertSame('invalid_syntax', $v->check('')['reason']);
        $this->assertSame('role_address', $v->check('noreply@brand.com')['reason']);
        $this->assertSame('role_address', $v->check('postmaster@brand.com')['reason']);
        $this->assertSame('disposable_domain', $v->check('x@mailinator.com')['reason']);
    }

    public function test_rejects_domains_that_cannot_receive_mail(): void
    {
        $this->assertSame('no_mx', $this->validator(false)->check('a@dead-domain.com')['reason']);
        $this->assertFalse($this->validator(false)->acceptable('a@dead-domain.com'));
    }

    public function test_reserved_test_tlds_skip_dns_entirely(): void
    {
        // No resolver injected: real DNS would be used, except for reserved TLDs.
        $this->assertTrue((new EmailValidator)->acceptable('creator@example.test'));
    }
}
