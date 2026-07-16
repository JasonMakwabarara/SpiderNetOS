<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Flow;
use Illuminate\Support\Str;

if (Flow::count() === 0) {
    $flows = [
        ['name' => 'Welcome Flow', 'description' => 'Automated welcome sequence', 'dag' => ['steps' => ['send_welcome', 'collect_info']]],
        ['name' => 'Data Pipeline', 'description' => 'Process and transform data', 'dag' => ['steps' => ['extract', 'transform', 'load']]],
        ['name' => 'Email Flow', 'description' => 'Send automated emails', 'dag' => ['steps' => ['prepare', 'send']]],
        ['name' => 'Sales Flow', 'description' => 'Sales follow-up automation', 'dag' => ['steps' => ['qualify', 'send_quote', 'follow_up']]],
    ];
    
    foreach ($flows as $flowData) {
        Flow::create([
            'id' => Str::uuid(),
            'name' => $flowData['name'],
            'slug' => Str::slug($flowData['name']),
            'description' => $flowData['description'],
            'dag' => json_encode($flowData['dag']),
            'triggers' => json_encode([]),
            'status' => 'draft',
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
        ]);
    }
    echo " Sample flows created!\n";
} else {
    echo " Flows already exist: " . Flow::count() . "\n";
}