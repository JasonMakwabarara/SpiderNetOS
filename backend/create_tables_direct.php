<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// Create tickets table if not exists
if (!Schema::hasTable('tickets')) {
    DB::statement('
        CREATE TABLE tickets (
            id TEXT PRIMARY KEY,
            tenant_id TEXT,
            title TEXT NOT NULL,
            description TEXT,
            status TEXT DEFAULT "open",
            priority TEXT DEFAULT "medium",
            created_by TEXT,
            assigned_to TEXT,
            created_at TEXT,
            updated_at TEXT
        )
    ');
    echo " tickets table created\n";
} else {
    echo " tickets table already exists\n";
}

// Create crm_records table if not exists
if (!Schema::hasTable('crm_records')) {
    DB::statement('
        CREATE TABLE crm_records (
            id TEXT PRIMARY KEY,
            tenant_id TEXT,
            field TEXT NOT NULL,
            value TEXT,
            updated_by TEXT,
            created_at TEXT,
            updated_at TEXT
        )
    ');
    echo " crm_records table created\n";
} else {
    echo " crm_records table already exists\n";
}
