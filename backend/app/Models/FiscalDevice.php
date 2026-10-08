<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FiscalDevice extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = ['tenant_id', 'name', 'device_identifier', 'status', 'credentials'];

    protected $hidden = ['credentials'];

    protected $appends = ['credentials_present'];

    public function getCredentialsPresentAttribute(): bool
    {
        return is_string($this->credentials) && $this->credentials !== '';
    }
}
