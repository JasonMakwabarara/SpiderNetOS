<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\Agent;
use Illuminate\Support\Str;

class AgentSeeder extends Seeder
{
    public function run()
    {
        if (Agent::count() == 0) {
            $agents = [
                ['name' => 'Sales Assistant', 'description' => 'Helps with sales and lead generation'],
                ['name' => 'Support Agent', 'description' => 'Provides customer support'],
                ['name' => 'Data Analyst', 'description' => 'Analyzes data and provides insights'],
            ];
            foreach ($agents as $a) {
                Agent::create([
                    'name' => $a['name'],
                    'slug' => Str::slug($a['name']),
                    'description' => $a['description'],
                    'type' => 'custom',
                    'capabilities' => ['chat'],
                    'status' => 'active',
                    'tenant_id' => '00000000-0000-0000-0000-000000000001'
                ]);
            }
            echo "✅ Agents seeded!\n";
        } else {
            echo "Agents already exist: " . Agent::count() . "\n";
        }
    }
}
