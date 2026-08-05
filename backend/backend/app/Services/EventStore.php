<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\TenantKeyManager;

class EventStore
{
    public function __construct(
        private readonly TenantKeyManager $tenantKeyManager
    ) {
    }

    /**
     * Append event to event_log.
     *
     * Supported invocation styles:
     * 1) append(tenantId, aggregateType, aggregateId, eventType, payload, metadata?, expectedVersion?)
     * 2) append(tenantId, eventType, payload, metadata?, expectedVersion?)
     */
    public function append(
        string $tenantId,
        string $aggregateType,
        mixed $aggregateId,
        mixed $eventType = null,
        array $payload = [],
        array $metadata = [],
        ?int $expectedVersion = null
    ): Event {
        [$resolvedAggregateType, $resolvedAggregateId, $resolvedEventType, $resolvedPayload, $resolvedMetadata, $resolvedExpectedVersion] =
            $this->normalizeAppendArguments(
                $aggregateType,
                $aggregateId,
                $eventType,
                $payload,
                $metadata,
                $expectedVersion
            );

        return DB::transaction(function () use (
            $tenantId,
            $resolvedAggregateType,
            $resolvedAggregateId,
            $resolvedEventType,
            $resolvedPayload,
            $resolvedMetadata,
            $resolvedExpectedVersion
        ) {
            $currentVersion = $this->getCurrentVersion($resolvedAggregateType, $resolvedAggregateId);
            
            if ($resolvedExpectedVersion !== null && $currentVersion !== $resolvedExpectedVersion) {
                throw new \RuntimeException(
                    "Concurrency conflict: expected version {$resolvedExpectedVersion}, found {$currentVersion}"
                );
            }
            
            $version = $currentVersion + 1;
            $sequenceNum = $this->getNextSequenceNum();
            
            $metadataWithRequest = array_merge($resolvedMetadata, [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            $previousHash = Event::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('sequence_num')
                ->value('hash');

            $eventId = (string) Str::uuid();
            $occurredAt = now();
            $signingMaterial = $this->tenantKeyManager->resolveActiveSigningMaterial($tenantId);
            $metadataWithSigning = array_merge($metadataWithRequest, [
                'signing_key_id' => $signingMaterial['key_id'],
            ]);
            $signingPayload = json_encode([
                'id' => $eventId,
                'tenant_id' => $tenantId,
                'aggregate_type' => $resolvedAggregateType,
                'aggregate_id' => $resolvedAggregateId,
                'event_type' => $resolvedEventType,
                'payload' => $resolvedPayload,
                'metadata' => $metadataWithSigning,
                'version' => $version,
                'occurred_at' => $occurredAt->toIso8601String(),
                'sequence_num' => $sequenceNum,
                'previous_hash' => $previousHash,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $eventHash = hash_hmac('sha256', $signingPayload, (string) $signingMaterial['key']);

            $event = new Event([
                'id' => $eventId,
                'tenant_id' => $tenantId,
                'aggregate_type' => $resolvedAggregateType,
                'aggregate_id' => $resolvedAggregateId,
                'event_type' => $resolvedEventType,
                'payload' => $resolvedPayload,
                'metadata' => $metadataWithSigning,
                'version' => $version,
                'occurred_at' => $occurredAt,
                'sequence_num' => $sequenceNum,
                'hash' => $eventHash,
                'previous_hash' => $previousHash,
            ]);
            
            $event->save();
            
            $this->dispatchToProjectors($event);
            
            return $event;
        });
    }
    
    public function appendMany(array $events): array
    {
        return DB::transaction(function () use ($events) {
            $saved = [];
            foreach ($events as $event) {
                $saved[] = $this->append(
                    $event['tenant_id'],
                    $event['aggregate_type'],
                    $event['aggregate_id'],
                    $event['event_type'],
                    $event['payload'],
                    $event['metadata'] ?? [],
                    $event['expected_version'] ?? null
                );
            }
            return $saved;
        });
    }
    
    public function getEvents(
        string $aggregateType,
        string $aggregateId,
        ?int $fromVersion = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = Event::forAggregate($aggregateType, $aggregateId);
        
        if ($fromVersion !== null) {
            $query->where('version', '>=', $fromVersion);
        }
        
        return $query->get();
    }
    
    public function getAllEvents(?string $since = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Event::orderBy('sequence_num');
        
        if ($since) {
            $query->since($since);
        }
        
        return $query->get();
    }
    
    public function rebuildProjection(string $projectionName, ?string $tenantId = null): void
    {
        DB::transaction(function () use ($projectionName, $tenantId) {
            DB::table($projectionName)->truncate();
            
            $query = Event::orderBy('sequence_num');
            if ($tenantId) {
                $query->forTenant($tenantId);
            }
            
            $projector = $this->resolveProjector($projectionName);
            
            foreach ($query->cursor() as $event) {
                $projector->handle($event);
            }
        });
    }
    
    private function normalizeAppendArguments(
        string $aggregateType,
        mixed $aggregateId,
        mixed $eventType,
        array $payload,
        array $metadata,
        ?int $expectedVersion
    ): array {
        // Short form: append(tenantId, eventType, payload, metadata?, expectedVersion?)
        if (is_array($aggregateId)) {
            $shortEventType = $aggregateType;
            $shortPayload = $aggregateId;
            $shortMetadata = is_array($eventType) ? $eventType : $payload;
            $shortExpectedVersion = is_int($eventType) ? $eventType : $expectedVersion;

            $parts = explode('.', $shortEventType, 2);
            $shortAggregateType = $parts[0] ?? 'system';
            $shortAggregateId = $shortPayload['aggregate_id'] ?? (string) Str::uuid();

            return [
                $shortAggregateType,
                (string) $shortAggregateId,
                $shortEventType,
                $shortPayload,
                $shortMetadata,
                $shortExpectedVersion,
            ];
        }

        // Full form
        return [
            $aggregateType,
            (string) $aggregateId,
            (string) $eventType,
            $payload,
            $metadata,
            $expectedVersion,
        ];
    }

    private function getCurrentVersion(string $aggregateType, string $aggregateId): int
    {
        return Event::forAggregate($aggregateType, $aggregateId)
            ->max('version') ?? 0;
    }
    
    private function getNextSequenceNum(): int
    {
        $row = DB::table('event_sequence')->lockForUpdate()->first();

        if (!$row) {
            DB::table('event_sequence')->insert(['id' => 1, 'next_num' => 2]);
            return 1;
        }

        $num = $row->next_num;
        DB::table('event_sequence')->where('id', $row->id)->update(['next_num' => $num + 1]);

        return $num;
    }
    
    private function dispatchToProjectors(Event $event): void
    {
        $projectors = config('projections.projectors', []);
        
        foreach ($projectors as $projectorClass) {
            $projector = app($projectorClass);
            if ($projector->accepts($event)) {
                $projector->handle($event);
            }
        }
    }
    
    private function resolveProjector(string $projectionName): object
    {
        $map = config('projections.map', []);
        $class = $map[$projectionName] ?? throw new \RuntimeException("Unknown projection: {$projectionName}");
        return app($class);
    }
}
