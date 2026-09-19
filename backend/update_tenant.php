<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;

$tenant = Tenant::first();

if ($tenant) {
    $tenant->onboarding_completed_at = now();
    $tenant->onboarding = 'completed';
    $tenant->status = 'active';
    $tenant->save();
    echo "✅ Tenant updated!\n";
    echo '   Name: '.$tenant->name."\n";
    echo '   Onboarding: '.$tenant->onboarding."\n";
} else {
    echo "❌ No tenant found!\n";
}
