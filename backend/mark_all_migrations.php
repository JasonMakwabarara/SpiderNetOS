<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$migrations = [
    '2026_07_06_000002_create_reports_table',
    '2026_07_06_000004_create_reports_table',
    '2026_07_06_000003_security_tables',
    '2026_07_06_000005_create_audit_logs_table',
    '2026_07_12_000000_create_agent_memories_table',
    '2026_07_07_000001_create_reports_table',
];

foreach ($migrations as $migration) {
    if (! DB::table('migrations')->where('migration', $migration)->exists()) {
        DB::table('migrations')->insert([
            'migration' => $migration,
            'batch' => 1,
        ]);
        echo " Marked: $migration\n";
    } else {
        echo " Already marked: $migration\n";
    }
}
