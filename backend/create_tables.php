<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

echo "Creating tables...\n";

// Create reports table
if (!Schema::hasTable('reports')) {
    Schema::create('reports', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('title');
        $table->string('type')->default('daily');
        $table->json('data')->nullable();
        $table->string('file_path')->nullable();
        $table->timestamp('generated_at')->nullable();
        $table->timestamps();
    });
    echo "? Reports table created\n";
} else {
    echo "? Reports table already exists\n";
}

// Create audit_logs table
if (!Schema::hasTable('audit_logs')) {
    Schema::create('audit_logs', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('action');
        $table->string('ip')->nullable();
        $table->text('metadata')->nullable();
        $table->timestamps();
    });
    echo "? audit_logs table created\n";
} else {
    echo "? audit_logs table already exists\n";
}

// Add 2FA columns to users table
if (!Schema::hasColumn('users', 'two_factor_secret')) {
    Schema::table('users', function (Blueprint $table) {
        $table->string('two_factor_secret')->nullable();
        $table->boolean('two_factor_enabled')->default(false);
    });
    echo "? 2FA columns added to users table\n";
} else {
    echo "? 2FA columns already exist\n";
}

echo "\nAll done!\n";
