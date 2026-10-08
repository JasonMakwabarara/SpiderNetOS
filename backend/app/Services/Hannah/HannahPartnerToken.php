<?php

declare(strict_types=1);

namespace App\Services\Hannah;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The RS256 assertion SpiderNet presents to Hannah AI to prove it is SpiderNet
 * (plan D7 §1).
 *
 * Asymmetric on purpose. The previous bridge between these two products used a
 * shared HS256 secret derived from APP_KEY, and both private keys ended up
 * sitting in a synced OneDrive folder as `.pem.txt`. A shared secret means
 * either side's leak forges both directions; with RS256 Hannah only ever holds
 * a public key, so a compromise of Hannah cannot mint SpiderNet assertions.
 *
 * The assertion is deliberately short-lived (300s) and carries a `jti` that
 * Hannah caches, so a captured assertion is useless twice.
 *
 * The private key is read from a FILE PATH, never from an inlined env value:
 * an env-inlined PEM ends up in `.env` backups, in `php artisan config:cache`
 * output and in crash reports.
 */
class HannahPartnerToken
{
    public const ALGORITHM = 'RS256';

    /**
     * @param  string  $tenantId  the subject: `tenant:<uuid>`
     * @param  array<string, mixed>  $claims  extra claims (scope, request id)
     *
     * @throws HannahNotConfiguredException when no signing key is available
     */
    public function assertionFor(string $tenantId, array $claims = []): string
    {
        $key = $this->privateKey();

        $now = time();
        $ttl = max(30, (int) config('services.hannah.assertion_ttl_seconds', 300));

        $header = [
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
            'kid' => (string) config('services.hannah.signing_key_id', 'spidernet-partner-1'),
        ];

        $payload = array_merge($claims, [
            'iss' => (string) config('services.hannah.issuer', 'spidernet'),
            'aud' => (string) config('services.hannah.audience', 'hannah-ai'),
            'sub' => 'tenant:'.$tenantId,
            'iat' => $now,
            'nbf' => $now - 5,          // a little clock slack, not a lot
            'exp' => $now + $ttl,
            'jti' => (string) Str::uuid(),
        ]);

        $signingInput = self::base64Url(json_encode($header, JSON_UNESCAPED_SLASHES))
            .'.'.self::base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new HannahNotConfiguredException('The Hannah signing key could not sign the assertion.');
        }

        return $signingInput.'.'.self::base64Url($signature);
    }

    public function configured(): bool
    {
        $path = trim((string) config('services.hannah.signing_key_path', ''));

        return $path !== '' && is_readable($path);
    }

    /**
     * @return \OpenSSLAsymmetricKey
     *
     * @throws HannahNotConfiguredException
     */
    private function privateKey()
    {
        $path = trim((string) config('services.hannah.signing_key_path', ''));

        if ($path === '') {
            throw new HannahNotConfiguredException(
                'HANNAH_SIGNING_KEY_PATH is not set. The partner key is a file path, not an env-inlined PEM.',
            );
        }
        if (! is_readable($path)) {
            throw new HannahNotConfiguredException("The Hannah signing key at {$path} is not readable.");
        }

        $pem = (string) file_get_contents($path);
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            Log::error('hannah.signing_key_invalid', ['path' => $path]);

            throw new HannahNotConfiguredException("The Hannah signing key at {$path} is not a usable private key.");
        }

        return $key;
    }

    /** Base64url without padding, as JWS requires. */
    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Decode a token's claims without verifying it.
     *
     * For logging and tests only — never for a trust decision. Verification
     * happens on Hannah's side, against the public key.
     *
     * @return array<string, mixed>
     */
    public static function peek(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $decoded = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($decoded) ? $decoded : [];
    }
}
