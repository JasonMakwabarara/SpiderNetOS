<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Agent;
use Illuminate\Support\Str;

if (Agent::count() === 0) {
    $agents = [
        ['name' => 'Sales Agent', 'description' => 'Handles sales inquiries', 'capabilities' => ['chat', 'data_analysis']],
        ['name' => 'Support Agent', 'description' => 'Provides customer support', 'capabilities' => ['chat', 'memory_access']],
        ['name' => 'Data Analyst', 'description' => 'Analyzes data and provides insights', 'capabilities' => ['data_analysis', 'flow_execution']],
    ];
    
    foreach ($agents as $agentData) {
        Agent::create([
            'id' => Str::uuid(),
            'name' => $agentData['name'],
            'slug' => Str::slug($agentData['name']),
            'description' => $agentData['description'],
            'capabilities' => json_encode($agentData['capabilities']),
            'status' => 'active',
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
        ]);
    }
    echo " Sample agents created!\n";
} else {
    echo " Agents already exist: " . Agent::count() . "\n";
}