<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FdmsRegistrationTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $conf = getenv('OPENSSL_CONF');
        if ($conf !== false && $conf !== '' && ! is_readable($conf)) {
            $this->markTestSkipped('OPENSSL_CONF points to a missing file, so OpenSSL cannot generate keys here.');
        }

        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fdms-reg-'.bin2hex(random_bytes(4));
        config([
            'fiscal.fdms.base_url' => 'https://fdms.test',
            'fiscal.fdms.device_id' => 321,
            'fiscal.fdms.model_name' => 'SpiderNetOS',
            'fiscal.fdms.model_version' => '1.0',
            'fiscal.fdms.serial_no' => 'HTA-001',
            'fiscal.fdms.key_path' => $this->dir.DIRECTORY_SEPARATOR.'device.key',
            'fiscal.fdms.cert_path' => $this->dir.DIRECTORY_SEPARATOR.'device.pem',
            'fiscal.fdms.key_passphrase' => null,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    public function test_registers_device_then_renews_certificate_for_the_same_key(): void
    {
        $csrSubjects = [];
        Http::fake(function (Request $request) use (&$csrSubjects) {
            return match (true) {
                str_ends_with($request->url(), '/Public/v1/321/VerifyTaxpayerInformation') => Http::response([
                    'operationID' => 'v1', 'taxPayerName' => 'Hammer and Tongues', 'taxPayerTIN' => '2000000000',
                    'vatNumber' => '220000001', 'deviceBranchName' => 'Harare',
                ]),
                str_ends_with($request->url(), '/Public/v1/321/RegisterDevice'),
                str_ends_with($request->url(), '/Device/v1/321/IssueCertificate') => (function () use ($request, &$csrSubjects) {
                    $csrSubjects[] = openssl_csr_get_subject($request['certificateRequest'])['CN'] ?? null;

                    return Http::response(['operationID' => 'r1', 'certificate' => $this->sign($request['certificateRequest'])]);
                })(),
                default => Http::response([], 404),
            };
        });

        $this->artisan('fdms:register', ['activation_key' => 'ABCD1234', '--yes' => true])
            ->expectsOutputToContain('Hammer and Tongues')
            ->expectsOutputToContain('ZIMRA-HTA-001-0000000321')
            ->assertSuccessful();

        $keyPem = (string) file_get_contents(config('fiscal.fdms.key_path'));
        $certPem = (string) file_get_contents(config('fiscal.fdms.cert_path'));
        $this->assertSame('ec', $this->keyType($keyPem));
        $this->assertTrue(openssl_x509_check_private_key($certPem, $keyPem));
        $this->assertSame(['ZIMRA-HTA-001-0000000321'], $csrSubjects);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'RegisterDevice')
            && $r['activationKey'] === 'ABCD1234'
            && $r->hasHeader('DeviceModelName', 'SpiderNetOS'));

        $this->artisan('fdms:register', ['activation_key' => 'ABCD1234', '--yes' => true])->assertFailed();
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), 'RegisterDevice')));

        $this->artisan('fdms:renew-certificate')->assertSuccessful();
        $renewed = (string) file_get_contents(config('fiscal.fdms.cert_path'));
        $this->assertNotSame($certPem, $renewed);
        $this->assertSame($keyPem, file_get_contents(config('fiscal.fdms.key_path')));
        $this->assertTrue(openssl_x509_check_private_key($renewed, $keyPem));
        $this->assertCount(1, glob(config('fiscal.fdms.cert_path').'.*.bak') ?: []);
    }

    public function test_refused_registration_discards_the_unused_key(): void
    {
        Http::fake([
            '*/VerifyTaxpayerInformation' => Http::response(['taxPayerName' => 'Hammer and Tongues']),
            '*/RegisterDevice' => Http::response(['errorCode' => 'DEV02', 'title' => 'Activation key is incorrect'], 422),
        ]);

        $this->artisan('fdms:register', ['activation_key' => 'WRONGKEY', '--yes' => true])
            ->expectsOutputToContain('DEV02')
            ->assertFailed();

        $this->assertFileDoesNotExist(config('fiscal.fdms.key_path'));
        $this->assertFileDoesNotExist(config('fiscal.fdms.cert_path'));
    }

    private function sign(string $csrPem): string
    {
        $caKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $cert = openssl_csr_sign($csrPem, null, $caKey, 365, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $pem);

        return $pem;
    }

    private function keyType(string $pem): string
    {
        return openssl_pkey_get_details(openssl_pkey_get_private($pem))['type'] === OPENSSL_KEYTYPE_EC ? 'ec' : 'other';
    }
}
