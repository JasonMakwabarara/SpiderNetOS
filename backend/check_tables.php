<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

if (Schema::hasTable('agents')) {
    echo " Agents table exists\n";
    echo 'Columns: '.implode(', ', Schema::getColumnListing('agents'))."\n";
} else {
    echo " Agents table does not exist\n";
}

if (Schema::hasTable('flows')) {
    echo " Flows table exists\n";
    echo 'Columns: '.implode(', ', Schema::getColumnListing('flows'))."\n";
} else {
    echo " Flows table does not exist\n";
}
