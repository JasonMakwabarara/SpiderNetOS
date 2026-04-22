<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantIntegration — Phase D
 *
 * Represents a third-party integration configured for a tenant
 * (e.g. Google Calendar, HubSpot).
 *
 * @property int    $id
 * @property string $tenant_id
 * @property string $provider          google_calendar | cal_com | hubspot | salesforce
 * @property string $type              calendar | crm | email
 * @property string|null $credentials_ref  reference key in tenant_secrets
 * @property bool   $is_active
 * @property array|null $config
 */
class TenantIntegration extends Model
{
    protected $table = 'tenant_integrations';

    protected $fillable = [
        'tenant_id',
        'provider',
        'type',
        'credentials_ref',
        'is_active',
        'config',
    ];

    protected $casts = [
        'config'    => 'array',
        'is_active' => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }
}
