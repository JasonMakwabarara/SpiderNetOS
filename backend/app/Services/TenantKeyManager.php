<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantKeyManager
{
    /**
     * Resolve tenant-scoped signing key for event chain HMAC.
     */
    public function resolveSigningKey(string $tenantId): string
    {
        return Cache::remember("tenant:event-signing-key:{$tenantId}", 300, function () use ($tenantId) {
            $secret = DB::table('tenant_secrets')
                ->where('tenant_id', $tenantId)
                ->where('key_name', 'event_signing')
                ->where('active', 1)
                ->orderByDesc('updated_at')
                ->value('secret_value');

            if ($secret) {
                return $this->decryptSecret((string) $secret);
            }

            // Fallback to deterministic app-level key namespace + tenant
            $base = (string) config('app.key', env('APP_KEY', 'spidernet-fallback-key'));

            return hash('sha256', $base.'|'.$tenantId.'|event_signing');
        });
    }

    /**
     * Resolve active signing material (id + decrypted secret).
     */
    public function resolveActiveSigningMaterial(string $tenantId): array
    {
        $row = DB::table('tenant_secrets')
            ->where('tenant_id', $tenantId)
            ->where('key_name', 'event_signing')
            ->where('active', 1)
            ->orderByDesc('updated_at')
            ->first(['id', 'secret_value']);

        if ($row) {
            return [
                'key_id' => (string) $row->id,
                'key' => $this->decryptSecret((string) $row->secret_value),
            ];
        }

        return [
            'key_id' => null,
            'key' => $this->resolveSigningKey($tenantId),
        ];
    }

    /**
     * Return all valid verification keys during dual-sign grace window.
     */
    public function resolveVerificationKeys(string $tenantId): array
    {
        $rows = DB::table('tenant_secrets')
            ->where('tenant_id', $tenantId)
            ->where('key_name', 'event_signing')
            ->where(function ($q) {
                $q->where('active', 1)
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('grace_until')->where('grace_until', '>', now());
                    });
            })
            ->orderByDesc('updated_at')
            ->get(['secret_value']);

        if ($rows->isEmpty()) {
            return [$this->resolveSigningKey($tenantId)];
        }

        return $rows->map(fn ($row) => $this->decryptSecret((string) $row->secret_value))->values()->all();
    }

    /**
     * Rotate signing key and keep previous key valid in grace window.
     */
    public function rotateSigningKey(string $tenantId, int $graceHours = 24): array
    {
        $now = now();
        $graceUntil = $now->copy()->addHours($graceHours);

        $previous = DB::table('tenant_secrets')
            ->where('tenant_id', $tenantId)
            ->where('key_name', 'event_signing')
            ->where('active', 1)
            ->orderByDesc('updated_at')
            ->first();

        if ($previous) {
            DB::table('tenant_secrets')
                ->where('id', $previous->id)
                ->update([
                    'active' => 0,
                    'grace_until' => $graceUntil,
                    'rotated_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $newSecret = Str::random(64);
        $newId = (string) Str::uuid();

        DB::table('tenant_secrets')->insert([
            'id' => $newId,
            'tenant_id' => $tenantId,
            'key_name' => 'event_signing',
            'secret_value' => $this->encryptSecret($newSecret),
            'active' => 1,
            'grace_until' => null,
            'rotated_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Cache::forget("tenant:event-signing-key:{$tenantId}");

        return [
            'new_secret_id' => $newId,
            'previous_secret_id' => $previous->id ?? null,
            'grace_until' => $previous ? $graceUntil->toIso8601String() : null,
        ];
    }

    /**
     * Ensure all tenants have active signing key records.
     */
    public function seedMissingSigningKeys(): int
    {
        $count = 0;
        $tenants = DB::table('tenants')->pluck('id');

        foreach ($tenants as $tenantId) {
            $exists = DB::table('tenant_secrets')
                ->where('tenant_id', $tenantId)
                ->where('key_name', 'event_signing')
                ->where('active', 1)
                ->exists();

            if (! $exists) {
                DB::table('tenant_secrets')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => (string) $tenantId,
                    'key_name' => 'event_signing',
                    'secret_value' => $this->encryptSecret(Str::random(64)),
                    'active' => 1,
                    'grace_until' => null,
                    'rotated_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }

        return $count;
    }

    private function encryptSecret(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    /**
     * Store an arbitrary tenant secret (e.g. integration credentials) encrypted
     * in tenant_secrets, superseding any previous value under the same name.
     * Returns the key_name used as the reference (tenant_integrations.credentials_ref).
     *
     * NOTE: this and getSecret()/forgetSecret() back the integrations layer —
     * IntegrationsController::authorize() and CalendarController both called
     * them before they existed, so connecting/using an integration fatally
     * failed. See tests/Feature/Connectors.
     */
    public function storeSecret(string $tenantId, string $keyName, string $value): string
    {
        DB::table('tenant_secrets')
            ->where('tenant_id', $tenantId)
            ->where('key_name', $keyName)
            ->update(['active' => 0, 'updated_at' => now()]);

        DB::table('tenant_secrets')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'key_name' => $keyName,
            'secret_value' => $this->encryptSecret($value),
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $keyName;
    }

    /** Read a stored tenant secret by its key_name; null when absent. */
    public function getSecret(string $tenantId, string $keyName): ?string
    {
        $value = DB::table('tenant_secrets')
            ->where('tenant_id', $tenantId)
            ->where('key_name', $keyName)
            ->where('active', 1)
            ->orderByDesc('updated_at')
            ->value('secret_value');

        return $value === null ? null : $this->decryptSecret((string) $value);
    }

    /** Deactivate a stored secret (used when disconnecting an integration). */
    public function forgetSecret(string $tenantId, string $keyName): void
    {
        DB::table('tenant_secrets')
            ->where('tenant_id', $tenantId)
            ->where('key_name', $keyName)
            ->update(['active' => 0, 'updated_at' => now()]);
    }

    public function resolveVerificationKeysForEvent(string $tenantId, ?string $keyId = null): array
    {
        $keys = [];

        if ($keyId) {
            $row = DB::table('tenant_secrets')
                ->where('tenant_id', $tenantId)
                ->where('id', $keyId)
                ->first(['secret_value']);
            if ($row) {
                $keys[] = $this->decryptSecret((string) $row->secret_value);
            }
        }

        foreach ($this->resolveVerificationKeys($tenantId) as $candidate) {
            if (! in_array($candidate, $keys, true)) {
                $keys[] = $candidate;
            }
        }

        return $keys;
    }

    private function decryptSecret(string $encrypted): string
    {
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            // Backward compatibility if plaintext exists
            return $encrypted;
        }
    }
}
