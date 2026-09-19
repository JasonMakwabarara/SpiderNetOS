<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\TenantKeyManager;
use Illuminate\Console\Command;

class VerifyEventChain extends Command
{
    protected $signature = 'events:verify-chain {tenant : Tenant UUID}';

    protected $description = 'Verify event hash chain integrity for tenant stream';

    public function handle(TenantKeyManager $keyManager): int
    {
        $tenantId = (string) $this->argument('tenant');
        $events = Event::forTenantOrdered($tenantId)->get();

        if ($events->isEmpty()) {
            $this->warn("No events found for tenant {$tenantId}");

            return self::SUCCESS;
        }

        $prevHash = null;
        $ok = true;
        $count = 0;

        foreach ($events as $event) {
            $metadata = is_array($event->metadata) ? $event->metadata : (json_decode((string) $event->metadata, true) ?: []);
            $keyId = $metadata['signing_key_id'] ?? null;
            $keys = $keyManager->resolveVerificationKeysForEvent($tenantId, $keyId);

            $payload = json_encode([
                'id' => $event->id,
                'tenant_id' => $event->tenant_id,
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => $event->aggregate_id,
                'event_type' => $event->event_type,
                'payload' => $event->payload,
                'metadata' => $metadata,
                'version' => $event->version,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'sequence_num' => $event->sequence_num,
                'previous_hash' => $event->previous_hash,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $computedMatches = false;
            foreach ($keys as $key) {
                if (hash_hmac('sha256', $payload, $key) === ($event->hash ?? null)) {
                    $computedMatches = true;
                    break;
                }
            }

            if (($event->previous_hash ?? null) !== $prevHash) {
                $this->error("Chain break at seq {$event->sequence_num}: previous_hash mismatch");
                $ok = false;
                break;
            }

            if (! $computedMatches) {
                $this->error("Hash mismatch at seq {$event->sequence_num}");
                $ok = false;
                break;
            }

            $prevHash = $event->hash;
            $count++;
        }

        if ($ok) {
            $this->info("Event chain valid for {$count} events (tenant {$tenantId}).");

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
