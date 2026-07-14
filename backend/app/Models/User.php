<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'email', 'password', 'tenant_id', 'role', 'capabilities'
    ];

    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['capabilities' => 'array'];
}
