<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// Check if executions column exists
$columns = DB::select("PRAGMA table_info(flows)");
$hasExecutions = false;
foreach ($columns as $col) {
    if ($col->name === 'executions') {
        $hasExecutions = true;
        break;
    }
}

if (!$hasExecutions) {
    DB::statement('ALTER TABLE flows ADD COLUMN executions INTEGER DEFAULT 0');
    echo " Added executions column to flows table\n";
} else {
    echo " executions column already exists\n";
}
