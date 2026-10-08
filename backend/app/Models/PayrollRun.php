<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = ['tenant_id', 'period_start', 'period_end', 'currency', 'status'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }
}
