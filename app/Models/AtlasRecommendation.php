<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AtlasRecommendation extends Model
{
    use HasUuids;

    protected $table = 'atlas_recommendations';
    protected $fillable = [
        'workspace_id', 'title', 'justification', 'impact_score',
        'expected_revenue_gain', 'estimated_time_saved', 'proposed_dag',
        'simulation_result', 'risk_score', 'status'
    ];
    protected $casts = [
        'proposed_dag' => 'array',
        'simulation_result' => 'array',
        'impact_score' => 'decimal:2',
        'expected_revenue_gain' => 'decimal:2',
        'risk_score' => 'decimal:2'
    ];
}
