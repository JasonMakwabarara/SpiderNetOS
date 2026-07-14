<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Mark security migration as done
$migration = '2026_07_06_000003_security_tables';
if (!DB::table('migrations')->where('migration', $migration)->exists()) {
    DB::table('migrations')->insert([
        'migration' => $migration,
        'batch' => 1
    ]);
    echo " Security migration marked as done\n";
} else {
    echo " Security migration already marked\n";
}
