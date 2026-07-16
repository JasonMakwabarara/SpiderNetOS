<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'pack_id', 'channel', 'key', 'subject', 'body',
        'whatsapp_template_sid', 'status',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
