<?php

/**
 * SQLite AI Training Tables Migration
 * Run: php scripts/migrate-ai-training-sqlite.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Capsule\Manager as Capsule;

// Setup Capsule for direct database access
$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

echo "=== SQLite AI Training Tables Migration ===\n";

// Create migrations table if not exists
if (! Capsule::schema()->hasTable('migrations')) {
    Capsule::schema()->create('migrations', function ($table) {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });
    echo "Created migrations table\n";
}

// Check if already migrated
$alreadyMigrated = Capsule::table('migrations')
    ->where('migration', '2026_05_04_000001_create_sqlite_ai_tables')
    ->exists();

if ($alreadyMigrated) {
    echo "Migration already applied. Skipping...\n";
    echo "\nExisting AI tables:\n";
    $tables = Capsule::select("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ai_%'");
    foreach ($tables as $table) {
        echo "  - {$table->name}\n";
    }
    exit(0);
}

// Create ai_training_bundles
if (! Capsule::schema()->hasTable('ai_training_bundles')) {
    Capsule::schema()->create('ai_training_bundles', function ($table) {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id')->nullable()->index();
        $table->string('bundle_name', 128)->index();
        $table->string('source_path', 512)->nullable();
        $table->string('source_type', 32)->default('cursor_markdown');
        $table->json('metadata')->nullable();
        $table->integer('total_turns')->default(0);
        $table->integer('sft_count')->default(0);
        $table->integer('preference_count')->default(0);
        $table->float('mean_quality_score')->default(0);
        $table->string('status', 32)->default('pending');
        $table->timestamp('gated_at')->nullable();
        $table->timestamp('applied_at')->nullable();
        $table->timestamps();
        $table->index(['tenant_id', 'status']);
        $table->index(['bundle_name', 'status']);
    });
    echo "Created ai_training_bundles\n";
}

// Create ai_training_examples
if (! Capsule::schema()->hasTable('ai_training_examples')) {
    Capsule::schema()->create('ai_training_examples', function ($table) {
        $table->uuid('id')->primary();
        $table->uuid('bundle_id')->index();
        $table->uuid('tenant_id')->nullable()->index();
        $table->text('user_prompt');
        $table->text('assistant_completion');
        $table->json('messages')->nullable();
        $table->integer('quality_score')->default(0);
        $table->json('metadata')->nullable();
        $table->string('label', 32)->nullable()->index();
        $table->boolean('included_in_sft')->default(false);
        $table->timestamps();
        $table->index(['bundle_id', 'quality_score']);
        $table->index(['tenant_id', 'quality_score']);
        $table->index(['bundle_id', 'included_in_sft']);
    });
    echo "Created ai_training_examples\n";
}

// Create ai_preference_pairs
if (! Capsule::schema()->hasTable('ai_preference_pairs')) {
    Capsule::schema()->create('ai_preference_pairs', function ($table) {
        $table->uuid('id')->primary();
        $table->uuid('bundle_id')->index();
        $table->uuid('tenant_id')->nullable()->index();
        $table->text('prompt');
        $table->text('chosen');
        $table->text('rejected');
        $table->integer('quality_score')->default(0);
        $table->string('rejected_type', 64)->default('unknown');
        $table->json('metadata')->nullable();
        $table->boolean('included_in_training')->default(false);
        $table->timestamps();
        $table->index(['bundle_id', 'quality_score']);
        $table->index(['bundle_id', 'rejected_type']);
        $table->index(['tenant_id', 'quality_score']);
    });
    echo "Created ai_preference_pairs\n";
}

// Create ai_quality_reports
if (! Capsule::schema()->hasTable('ai_quality_reports')) {
    Capsule::schema()->create('ai_quality_reports', function ($table) {
        $table->uuid('id')->primary();
        $table->uuid('bundle_id')->index();
        $table->uuid('tenant_id')->nullable()->index();
        $table->boolean('gate_passed')->default(false);
        $table->json('gate_results')->nullable();
        $table->json('metrics')->nullable();
        $table->json('distributions')->nullable();
        $table->integer('sft_rows')->default(0);
        $table->integer('preference_rows')->default(0);
        $table->float('sft_mean_quality')->default(0);
        $table->float('preference_mean_quality')->default(0);
        $table->float('distinct_preference_ratio')->default(0);
        $table->json('rejected_type_distribution')->nullable();
        $table->timestamps();
        $table->index(['bundle_id', 'gate_passed']);
        $table->index(['tenant_id', 'gate_passed']);
    });
    echo "Created ai_quality_reports\n";
}

// Record migration
Capsule::table('migrations')->insert([
    'migration' => '2026_05_04_000001_create_sqlite_ai_tables',
    'batch' => 1,
]);

echo "\n=== Migration Complete ===\n";
echo "AI training tables created in SQLite database\n";

echo "\nCreated tables:\n";
$tables = Capsule::select("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ai_%'");
foreach ($tables as $table) {
    echo "  - {$table->name}\n";
}

echo "\nNext steps:\n";
echo "  1. Run training data conversion:\n";
echo "     python scripts\\convert_cursor_markdown_to_jsonl.py --input '<file>' --out-dir training_data\n";
echo "  2. Evaluate quality:\n";
echo "     python scripts\\eval_training_data_quality.py --sft training_data\\sft.jsonl --preference training_data\\preference.jsonl\n";
