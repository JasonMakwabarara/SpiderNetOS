<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalPolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'resource_type', 'action', 'name',
        'enabled', 'priority', 'conditions',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'priority' => 'integer',
        'conditions' => 'array',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalPolicyStep::class)->orderBy('step_order');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Evaluate the policy's conditions against resource attributes.
     * Unknown condition keys are ignored; an empty conditions object
     * matches everything.
     */
    public function matches(array $attributes): bool
    {
        $conditions = $this->conditions ?? [];

        if (isset($conditions['min_amount']) && $conditions['min_amount'] !== null) {
            $amount = (string) ($attributes['amount'] ?? '0');
            if (bccomp($amount, (string) $conditions['min_amount'], 4) < 0) {
                return false;
            }
        }

        if (isset($conditions['max_amount']) && $conditions['max_amount'] !== null) {
            $amount = (string) ($attributes['amount'] ?? '0');
            if (bccomp($amount, (string) $conditions['max_amount'], 4) > 0) {
                return false;
            }
        }

        if (! empty($conditions['currency'])
            && isset($attributes['currency'])
            && strcasecmp((string) $attributes['currency'], (string) $conditions['currency']) !== 0) {
            return false;
        }

        if (! empty($conditions['categories']) && isset($attributes['category'])) {
            if (! in_array($attributes['category'], (array) $conditions['categories'], true)) {
                return false;
            }
        }

        return true;
    }
}
