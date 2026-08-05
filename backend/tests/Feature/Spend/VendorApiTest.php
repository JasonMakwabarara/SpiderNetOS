<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorApiTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Vendor Tenant',
            'slug' => 'vendor-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role = 'member'): User
    {
        return User::create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/financial/vendors')->assertStatus(401);
    }

    public function test_create_show_update_list(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $vendor = $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/vendors', [
                'name' => 'Acme Supplies',
                'email' => 'billing@acme.test',
                'currency' => 'USD',
                'payment_terms_days' => 30,
                'address' => ['line1' => '1 Acme Way', 'city' => 'Springfield'],
                'bank_details' => ['iban' => 'GB33BUKB20201555555555'],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('active', $vendor['status']);
        $this->assertDatabaseHas('vendors', [
            'id' => $vendor['id'],
            'tenant_id' => $tenant->id,
            'name' => 'Acme Supplies',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/vendors/'.$vendor['id'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Supplies')
            ->assertJsonPath('data.payment_terms_days', 30)
            ->assertJsonPath('data.address.city', 'Springfield');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/financial/vendors/'.$vendor['id'], [
                'name' => 'Acme Supplies Ltd',
                'payment_terms_days' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Supplies Ltd')
            ->assertJsonPath('data.payment_terms_days', 45);

        $list = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/vendors')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $list['total']);
    }

    public function test_validation_rejects_bad_payload(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/vendors', [
                'email' => 'not-an-email',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_archive_and_status_filter(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $keep = Vendor::create(['tenant_id' => $tenant->id, 'name' => 'Keep Me']);
        $archive = Vendor::create(['tenant_id' => $tenant->id, 'name' => 'Archive Me']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/vendors/'.$archive->id.'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $active = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/vendors?status=active')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $active['total']);
        $this->assertSame($keep->id, $active['data'][0]['id']);

        $archived = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/vendors?status=archived')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $archived['total']);
    }

    public function test_cross_tenant_isolation(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $userB = $this->createUser($tenantB);

        $vendorA = Vendor::create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Vendor']);

        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/financial/vendors/'.$vendorA->id)
            ->assertNotFound();

        $this->actingAs($userB, 'sanctum')
            ->putJson('/api/financial/vendors/'.$vendorA->id, ['name' => 'Hijack'])
            ->assertNotFound();

        $list = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/financial/vendors')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $list['total']);
    }
}
