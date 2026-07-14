<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$user = User::where('email', 'admin@spidernetos.com')->first();

if (!$user) {
    $user = User::create([
        'name' => 'Admin',
        'email' => 'admin@spidernetos.com',
        'password' => Hash::make('Zukaarimoto01!'),
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'onboarding_completed_at' => now(),
    ]);
    echo "✅ User created!\n";
} else {
    $user->onboarding_completed_at = now();
    $user->save();
    echo "✅ User updated! Onboarding completed at: " . $user->onboarding_completed_at . "\n";
}