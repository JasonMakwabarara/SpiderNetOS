<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Flow extends Model
{
    use HasUuids;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','tenant_id','name','slug','description','dag','triggers','status','published_at','schedule_cron','schedule_timezone','last_scheduled_at'];
    protected $casts = ['dag'=>'array','triggers'=>'array','published_at'=>'datetime','last_scheduled_at'=>'datetime'];

    protected static function booted()
    {
        static::creating(function ($flow) {
            if (empty($flow->id)) $flow->id = (string) \Illuminate\Support\Str::uuid();
            if (empty($flow->slug)) $flow->slug = \Illuminate\Support\Str::slug($flow->name);
            if (empty($flow->tenant_id)) $flow->tenant_id = '00000000-0000-0000-0000-000000000001';
            if (empty($flow->status)) $flow->status = 'draft';
        });
    }
}
