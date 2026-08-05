<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Mark the problematic migration as done
$migration = '2026_04_17_103000_add_fingerprint_and_divergence_tables';
if (!DB::table('migrations')->where('migration', $migration)->exists()) {
    DB::table('migrations')->insert([
        'migration' => $migration,
        'batch' => 1
    ]);
    echo " Migration marked as done: $migration\n";
} else {
    echo " Migration already marked: $migration\n";
}
