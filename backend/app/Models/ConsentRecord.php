<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only-ish consent audit trail for messaging channels. The latest row
 * for (tenant, subject, channel) is authoritative; history is retained for
 * compliance evidence.
 */
class ConsentRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'subject', 'channel', 'status', 'source', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Record a consent change and return the new row.
     */
    public static function log(string $tenantId, string $subject, string $channel, string $status, ?string $source = null, array $meta = []): self
    {
        return self::create([
            'tenant_id' => $tenantId,
            'subject' => $subject,
            'channel' => $channel,
            'status' => $status,
            'source' => $source,
            'meta' => $meta,
        ]);
    }

    /**
     * Is the subject currently allowed on this channel? Latest record wins;
     * defaults to false (explicit opt-in) when there is no record.
     */
    public static function isAllowed(string $tenantId, string $subject, string $channel): bool
    {
        // Ordered UUIDs (HasUuids) encode insertion time, so id is a
        // deterministic tiebreaker when created_at collides at second precision.
        $latest = self::forTenant($tenantId)
            ->where('subject', $subject)
            ->where('channel', $channel)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $latest?->status === 'granted';
    }
}
