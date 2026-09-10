<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\TenantIntegration;
use App\Services\Connectors\ConnectorManager;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Builds a Laravel mailer from a tenant's own SMTP credentials (the zoho_mail
 * connector), so outbound partner mail is sent AS the tenant's mailbox instead
 * of through the platform's default transport.
 *
 * Not final on purpose: tests bind a subclass whose fromCredentials() returns a
 * mock mailer, because Mail::fake() does not intercept on-demand mailers.
 */
class TenantMailerFactory
{
    public const PROVIDER = 'zoho_mail';

    /** @var array<string, Mailer|null> */
    private array $cache = [];

    public function __construct(private readonly ConnectorManager $connectors) {}

    /** The tenant's mailer, or null when no active mailbox connection exists. */
    public function for(string $tenantId): ?Mailer
    {
        if (array_key_exists($tenantId, $this->cache)) {
            return $this->cache[$tenantId];
        }

        $credentials = $this->credentialsFor($tenantId);

        return $this->cache[$tenantId] = $this->hasSmtp($credentials) ? $this->fromCredentials($credentials) : null;
    }

    /** Decrypted zoho_mail credentials for a tenant (empty when not connected). */
    public function credentialsFor(string $tenantId): array
    {
        $integration = TenantIntegration::forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->where('is_active', true)
            ->first();

        return $integration ? $this->connectors->credentialsFor($integration) : [];
    }

    /** @param array<string, mixed> $credentials */
    public function hasSmtp(array $credentials): bool
    {
        foreach (['smtp_host', 'smtp_username', 'smtp_password'] as $key) {
            if (empty($credentials[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build an on-demand SMTP mailer. Port 465 becomes implicit TLS (smtps),
     * anything else uses STARTTLS: Laravel derives the scheme from the port
     * when 'encryption' is tls.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function fromCredentials(array $credentials): Mailer
    {
        return Mail::build([
            'transport' => 'smtp',
            'host' => (string) ($credentials['smtp_host'] ?? 'smtp.zoho.com'),
            'port' => (int) ($credentials['smtp_port'] ?? 587),
            'encryption' => 'tls',
            'username' => (string) ($credentials['smtp_username'] ?? ''),
            'password' => (string) ($credentials['smtp_password'] ?? ''),
            'timeout' => 20,
        ]);
    }

    /**
     * From address + name for a tenant mailbox (falls back to the SMTP login).
     *
     * @param  array<string, mixed>  $credentials
     * @return array{address: string, name: ?string}
     */
    public function senderFor(array $credentials): array
    {
        $address = trim((string) ($credentials['from_address'] ?? ''));
        if ($address === '') {
            $address = trim((string) ($credentials['smtp_username'] ?? ''));
        }

        $name = trim((string) ($credentials['from_name'] ?? ''));

        return ['address' => $address, 'name' => $name !== '' ? $name : null];
    }

    public function forget(string $tenantId): void
    {
        unset($this->cache[$tenantId]);
    }
}
