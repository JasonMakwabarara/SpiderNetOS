<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class AgentMemory extends Model { use HasUuids; protected $keyType = 'string'; public $incrementing = false; protected $fillable = ['agent_id','user_id','summary','key_facts','sentiment']; protected $casts = ['key_facts'=>'array']; }
