<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CrmRecord extends Model
{
    use HasUuids;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $table = 'crm_records';
    protected $fillable = ['id', 'tenant_id', 'field', 'value', 'updated_by'];
    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];
}
