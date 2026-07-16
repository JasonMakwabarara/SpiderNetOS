<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

Schema::create('ste_event_mapping', function (Blueprint $table) {
    $table->id();
    $table->string('event_type');
    $table->string('chain');
    $table->string('from_state')->nullable();
    $table->string('to_state');
    $table->json('extract_tags')->nullable();
    $table->boolean('enabled')->default(true);
    $table->timestamps();
});
echo "✅ Table created!\n";