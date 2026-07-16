<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Report extends Model
{
    use HasUuids;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['title', 'type', 'data', 'file_path', 'generated_at'];
    protected $casts = ['data' => 'array', 'generated_at' => 'datetime'];
}
