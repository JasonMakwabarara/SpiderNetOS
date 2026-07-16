<?php

namespace Database\Seeders;

use App\Services\EventStore;
use App\Services\TenantKeyManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the SpiderNet OS database via EventStore (Hard Rule #1).
     * Creates: default tenant, admin user, 6 core agents, delegation edges, plan tiers.
     */
    public function run(): void
    {
        $eventStore = app(EventStore::class);
        
        // ─── Default Tenant ─────────────────────────────────
        $tenantId = '00000000-0000-0000-0000-000000000001';
        
        $eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'tenant',
            aggregateId: $tenantId,
            eventType: 'tenant.created',
            payload: [
                'name' => 'SpiderNet Development',
                'slug' => 'spidernet-dev',
                'plan' => 'growth',
                'status' => 'active',
            ],
        );
        
        // Ensure tenant exists in projection
        DB::table('tenants')->updateOrInsert(
            ['id' => $tenantId],
            [
                'name' => 'SpiderNet Development',
                'slug' => 'spidernet-dev',
                'plan' => 'growth',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // ─── Admin User ─────────────────────────────────────
        $userId = '00000000-0000-0000-0000-000000000002';
        
        $eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'user',
            aggregateId: $userId,
            eventType: 'user.created',
            payload: [
                'name' => 'SpiderNet Admin',
                'email' => 'admin@spidernetos.com',
                'password' => Hash::make('Zukaarimoto01!'),
                'role' => 'super_admin',
                'tenant_id' => $tenantId,
            ],
        );
        
        // Ensure user exists in projection
        DB::table('users')->updateOrInsert(
            ['id' => $userId],
            [
                'tenant_id' => $tenantId,
                'name' => 'SpiderNet Admin',
                'email' => 'admin@spidernetos.com',
                'password' => Hash::make('Zukaarimoto01!'),
                'role' => 'super_admin',
                'is_platform_admin' => true,
                'onboarding_completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // ─── 6 Core Agents ─────────────────────────────────
        $agents = [
            [
                'id' => '00000000-0000-0000-0000-000000000010',
                'name' => 'Atlas',
                'slug' => 'atlas',
                'description' => 'NL Compiler & Executive Assistant — parses natural language into structured commands and orchestrates agent dispatch.',
                'type' => 'core',
                'status' => 'active',
                'capabilities' => ['nl_compilation', 'delegation', 'memory_management'],
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000011',
                'name' => 'Hannah',
                'slug' => 'hannah',
                'description' => 'Tutor & Onboarding Guide — helps users understand the system through guided learning paths.',
                'type' => 'core',
                'status' => 'active',
                'capabilities' => ['onboarding', 'teaching', 'help'],
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000012',
                'name' => 'Forge',
                'slug' => 'forge',
                'description' => 'Flow Builder & DAG Designer — creates, modifies, and validates workflow DAGs from natural language.',
                'type' => 'core',
                'status' => 'active',
                'capabilities' => ['flow_creation', 'flow_modification', 'flow_validation'],
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000013',
                'name' => 'Sentinel',
                'slug' => 'sentinel',
                'description' => 'Monitor & Anomaly Detector — watches system health and detects anomalies using z-score analysis.',
                'type' => 'core',
                'status' => 'active',
                'capabilities' => ['health_monitoring', 'anomaly_detection', 'alerting', 'trace_management'],
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000014',
                'name' => 'Prism',
                'slug' => 'prism',
                'description' => 'Data Analyst & Researcher — performs data analysis, research, summarization, and report generation.',
                'type' => 'core',
                'status' => 'active',
                'capabilities' => ['data_analysis', 'research', 'summarization', 'report_generation'],
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000015',
                'name' => 'Nexus',
                'slug' => 'nexus',
                'description' => 'Execution Engine & DAG Runner — executes workflow DAGs with node chaining, retry logic, and parallel dispatch.',
                'type' => 'core',
                'status' => 'active',
                'capabilities' => ['dag_execution', 'retry_management'],
            ],
        ];

        foreach ($agents as $agent) {
            $eventStore->append(
                tenantId: $tenantId,
                aggregateType: 'agent',
                aggregateId: $agent['id'],
                eventType: 'agent.registered',
                payload: $agent,
            );
            
            // Ensure agent exists in projection
            DB::table('agents')->updateOrInsert(
                ['id' => $agent['id']],
                [
                    'tenant_id' => $tenantId,
                    'name' => $agent['name'],
                    'slug' => $agent['slug'],
                    'description' => $agent['description'],
                    'type' => $agent['type'],
                    'status' => 'active',
                    'capabilities' => json_encode($agent['capabilities']),
                    'config' => json_encode([]),
                    'activated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // ─── Delegation Edges ───────────────────────────────
        // Atlas can delegate to all agents
        $atlasId = '00000000-0000-0000-0000-000000000010';
        $delegations = [
            ['delegate_id' => '00000000-0000-0000-0000-000000000011', 'permission' => 'teach'],
            ['delegate_id' => '00000000-0000-0000-0000-000000000012', 'permission' => 'flow_creation'],
            ['delegate_id' => '00000000-0000-0000-0000-000000000013', 'permission' => 'health_monitoring'],
            ['delegate_id' => '00000000-0000-0000-0000-000000000014', 'permission' => 'data_analysis'],
            ['delegate_id' => '00000000-0000-0000-0000-000000000015', 'permission' => 'dag_execution'],
        ];

        foreach ($delegations as $delegation) {
            DB::table('agent_delegations')->updateOrInsert(
                [
                    'agent_id' => $atlasId,
                    'delegate_id' => $delegation['delegate_id'],
                ],
                [
                    'permission' => $delegation['permission'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Nexus can delegate to Forge (for flow modification during execution)
        DB::table('agent_delegations')->updateOrInsert(
            [
                'agent_id' => '00000000-0000-0000-0000-000000000015',
                'delegate_id' => '00000000-0000-0000-0000-000000000012',
            ],
            [
                'permission' => 'flow_modification',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // ─── Cost Budget ────────────────────────────────────
        DB::table('cost_budgets')->updateOrInsert(
            ['tenant_id' => $tenantId],
            [
                'id' => (string) Str::uuid(),
                'daily_limit' => 10.00,
                'monthly_limit' => 100.00,
                'alert_threshold' => 0.80,
                'action_at_limit' => 'degrade',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // ─── Event Sequence Initialization ──────────────────
        DB::table('event_sequence')->updateOrInsert(
            ['id' => 1],
            ['next_num' => 100] // Start above seed events
        );

        // ─── Tenant Signing Secrets ──────────────────────────
        app(TenantKeyManager::class)->seedMissingSigningKeys();

        // ─── STE event-type mappings ─────────────────────────
        $this->call(SteEventMappingSeeder::class);

        // ─── Platform plan catalog (Launch/Growth/Enterprise) ─
        $this->call(PlanCatalogSeeder::class);

        $this->command->info('Seeded: 1 tenant, 1 user, 6 core agents, 6 delegation edges, 1 cost budget, tenant signing secrets, STE event mappings, plan catalog');
    }
}
