<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Agent extends Model
{
    use HasUuids;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','tenant_id','name','slug','description','type','status','capabilities','config','activated_at'];
    protected $casts = ['capabilities'=>'array','config'=>'array','activated_at'=>'datetime'];

    protected static function booted()
    {
        static::creating(function ($agent) {
            if (empty($agent->id)) $agent->id = (string) \Illuminate\Support\Str::uuid();
            if (empty($agent->slug)) $agent->slug = \Illuminate\Support\Str::slug($agent->name);
            if (empty($agent->tenant_id)) $agent->tenant_id = '00000000-0000-0000-0000-000000000001';
            if (empty($agent->type)) $agent->type = 'custom';
            if (empty($agent->status)) $agent->status = 'active';
        });
    }
}
