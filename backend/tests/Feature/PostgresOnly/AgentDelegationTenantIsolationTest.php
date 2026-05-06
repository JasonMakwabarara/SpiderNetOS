<?php

declare(strict_types=1);

namespace Tests\Feature\PostgresOnly;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Postgres-only: excluded from default Feature suite (phpunit.xml) because SQLite
 * cannot migrate pgvector columns; executed in CI via phpunit.github-full.xml.
 */
class AgentDelegationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delegations_and_show_exclude_cross_tenant_delegate_edges(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-'.Str::lower(Str::random(10)),
            'status' => 'active',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-'.Str::lower(Str::random(10)),
            'status' => 'active',
            'plan' => 'pro',
        ]);

        $delegatorId = (string) Str::uuid();
        $goodDelegateId = (string) Str::uuid();
        $foreignDelegateId = (string) Str::uuid();

        $now = now();
        $cap = json_encode(['chat' => true]);
        $cfg = json_encode(new \stdClass);

        foreach ([
            [$delegatorId, $tenantA->id, 'Delegator', 'delegator-a', 'dynamic'],
            [$goodDelegateId, $tenantA->id, 'Good delegate', 'delegate-good', 'static'],
            [$foreignDelegateId, $tenantB->id, 'Foreign delegate', 'delegate-foreign', 'static'],
        ] as [$id, $tid, $name, $slug, $type]) {
            DB::table('agents')->insert([
                'id' => $id,
                'tenant_id' => $tid,
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'type' => $type,
                'status' => 'active',
                'capabilities' => $cap,
                'config' => $cfg,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('agent_delegations')->insert([
            [
                'agent_id' => $delegatorId,
                'delegate_id' => $goodDelegateId,
                'permission' => 'read',
                'conditions' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'agent_id' => $delegatorId,
                'delegate_id' => $foreignDelegateId,
                'permission' => 'read',
                'conditions' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $user = User::create([
            'name' => 'Member',
            'email' => 'member@tenant-a.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantA->id,
            'role' => 'admin',
            'onboarding_completed_at' => $now,
        ]);

        $this->actingAs($user, 'sanctum');

        $delegationsResp = $this->getJson("/api/agents/{$delegatorId}/delegations");
        $delegationsResp->assertOk();
        $rows = $delegationsResp->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($goodDelegateId, $rows[0]['agent_id']);
        $this->assertSame('delegate-good', $rows[0]['slug']);

        $showResp = $this->getJson("/api/agents/{$delegatorId}");
        $showResp->assertOk();
        $embedded = $showResp->json('data.delegations');
        $this->assertCount(1, $embedded);
        $this->assertSame('delegate-good', $embedded[0]['slug']);

        $graphResp = $this->getJson('/api/agents/graph/delegation');
        $graphResp->assertOk();
        $targets = collect($graphResp->json('graph.edges'))->pluck('target')->all();
        $this->assertContains($goodDelegateId, $targets);
        $this->assertNotContains($foreignDelegateId, $targets);
    }
}
