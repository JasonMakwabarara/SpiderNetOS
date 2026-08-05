<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'plan',
        'settings',
        'limits',
        'trial_ends_at',
        'subscribed_at',
        'status',
        'onboarding',
        'onboarding_completed_at',
        'automation_level',
    ];
    
    protected $casts = [
        'settings' => 'array',
        'limits' => 'array',
        'onboarding' => 'array',
        'trial_ends_at' => 'datetime',
        'subscribed_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
    ];
    
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
    
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
    
    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }

    public function featurePacks(): HasMany
    {
        return $this->hasMany(FeaturePack::class);
    }
    
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
    
    public function isInTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }
}
