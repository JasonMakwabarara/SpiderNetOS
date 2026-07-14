<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Ticket extends Model
{
    use HasUuids;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $table = 'tickets';
    protected $fillable = ['id', 'tenant_id', 'title', 'description', 'status', 'priority', 'created_by', 'assigned_to'];
    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];
}
