<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$user = User::where('email', 'admin@spidernetos.com')->first();
if (!$user) {
    User::create([
        'id' => (string) Str::uuid(),
        'name' => 'Admin',
        'email' => 'admin@spidernetos.com',
        'password' => Hash::make('Zukaarimoto01!'),
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'role' => 'admin'
    ]);
    echo " Admin user created\n";
} else {
    echo " Admin user already exists\n";
}
