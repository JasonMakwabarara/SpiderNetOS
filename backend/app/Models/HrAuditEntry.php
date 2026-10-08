<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class HrAuditEntry extends Model
{
    use HasUuids, TenantScoped;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'actor_user_id',
        'event',
        'field',
        'previous_value',
        'new_value',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('HR audit entries cannot be updated.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('HR audit entries cannot be deleted.');
    }
}
