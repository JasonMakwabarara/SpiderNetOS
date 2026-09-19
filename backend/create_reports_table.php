<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasTable('reports')) {
    DB::statement('
        CREATE TABLE reports (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            type TEXT DEFAULT "daily",
            data TEXT,
            file_path TEXT,
            generated_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )
    ');
    echo " reports table created\n";
} else {
    echo " reports table already exists\n";
}
