<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FlowSeeder extends Seeder
{
    public function run()
    {
        DB::table('flows')->insert([
            [
                'name' => 'Order Processing',
                'description' => 'Process orders',
                'trigger' => 'webhook',
                'executions' => 1247,
                'icon' => '📦',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Customer Onboarding',
                'description' => 'Onboard customers',
                'trigger' => 'schedule',
                'executions' => 892,
                'icon' => '🎉',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Data Sync',
                'description' => 'Sync data',
                'trigger' => 'event',
                'executions' => 3421,
                'icon' => '🔄',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}