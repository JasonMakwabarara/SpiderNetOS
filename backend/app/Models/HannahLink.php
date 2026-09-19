<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A tenant's identity inside Hannah AI (plan D7 §1). */
class HannahLink extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_LINKED = 'linked';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_LINKED, self::STATUS_FAILED, self::STATUS_REVOKED];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected $fillable = [
        'tenant_id', 'linked_by', 'hannah_user_id', 'hannah_workspace_id', 'hannah_company_id',
        'open_id', 'owner_email', 'webhook_secret_ref', 'last_brand_hash', 'last_brand_synced_at',
        'status', 'error', 'terms_accepted_at', 'terms_accepted_by', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_brand_synced_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
    ];

    protected $hidden = ['webhook_secret_ref'];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isLinked(): bool
    {
        return $this->status === self::STATUS_LINKED && $this->hannah_user_id !== null;
    }
}
