<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Models\SpendDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptUploadTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Receipt Tenant',
            'slug' => 'receipt-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role = 'member'): User
    {
        return User::create([
            'name' => 'Receipt User',
            'email' => $role.'-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }

    /** @return array{report: array, item_id: string} */
    private function createReportWithItem(User $user): array
    {
        $report = $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses', [
                'title' => 'Receipt run',
                'items' => [[
                    'description' => 'Hotel',
                    'amount' => '210.00',
                    'expense_date' => now()->toDateString(),
                ]],
            ])
            ->assertCreated()
            ->json('data');

        return ['report' => $report, 'item_id' => $report['items'][0]['id']];
    }

    public function test_upload_sets_has_receipt_sha256_and_document_row(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        ['report' => $report, 'item_id' => $itemId] = $this->createReportWithItem($user);

        $file = UploadedFile::fake()->image('receipt.jpg', 640, 480);

        $document = $this->actingAs($user, 'sanctum')
            ->post(
                '/api/financial/expenses/'.$report['id'].'/items/'.$itemId.'/receipt',
                ['file' => $file],
                ['Accept' => 'application/json'],
            )
            ->assertCreated()
            ->json('data');

        $this->assertSame('receipt', $document['kind']);
        $this->assertSame('uploaded', $document['status']);
        $this->assertSame('receipt.jpg', $document['original_filename']);
        $this->assertSame(64, strlen($document['sha256']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $document['sha256']);

        $this->assertDatabaseHas('spend_documents', [
            'id' => $document['id'],
            'tenant_id' => $tenant->id,
            'kind' => 'receipt',
            'attachable_id' => $itemId,
            'uploaded_by' => $user->id,
        ]);
        $this->assertDatabaseHas('expense_items', ['id' => $itemId, 'has_receipt' => true]);

        Storage::disk('local')->assertExists($document['path']);
        $this->assertStringStartsWith("spend/{$tenant->id}/receipts/", $document['path']);

        $this->assertDatabaseHas('event_log', ['event_type' => 'expense_report.receipt_attached']);
    }

    public function test_remove_receipt_clears_has_receipt_and_deletes_file(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        ['report' => $report, 'item_id' => $itemId] = $this->createReportWithItem($user);

        $document = $this->actingAs($user, 'sanctum')
            ->post(
                '/api/financial/expenses/'.$report['id'].'/items/'.$itemId.'/receipt',
                ['file' => UploadedFile::fake()->image('receipt.png')],
                ['Accept' => 'application/json'],
            )
            ->assertCreated()
            ->json('data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/financial/expenses/'.$report['id'].'/receipts/'.$document['id'])
            ->assertOk();

        $this->assertDatabaseMissing('spend_documents', ['id' => $document['id']]);
        $this->assertDatabaseHas('expense_items', ['id' => $itemId, 'has_receipt' => false]);
        Storage::disk('local')->assertMissing($document['path']);
    }

    public function test_rejects_disallowed_mime_type(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        ['report' => $report, 'item_id' => $itemId] = $this->createReportWithItem($user);

        $this->actingAs($user, 'sanctum')
            ->post(
                '/api/financial/expenses/'.$report['id'].'/items/'.$itemId.'/receipt',
                ['file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
                ['Accept' => 'application/json'],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, SpendDocument::count());
    }

    public function test_rejects_oversized_file(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        ['report' => $report, 'item_id' => $itemId] = $this->createReportWithItem($user);

        // max:10240 KB — send 11 MB.
        $this->actingAs($user, 'sanctum')
            ->post(
                '/api/financial/expenses/'.$report['id'].'/items/'.$itemId.'/receipt',
                ['file' => UploadedFile::fake()->create('huge.pdf', 11264, 'application/pdf')],
                ['Accept' => 'application/json'],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, SpendDocument::count());
    }

    public function test_upload_is_tenant_scoped(): void
    {
        Storage::fake('local');

        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $userA = $this->createUser($tenantA);
        $userB = $this->createUser($tenantB);
        ['report' => $report, 'item_id' => $itemId] = $this->createReportWithItem($userA);

        $this->actingAs($userB, 'sanctum')
            ->post(
                '/api/financial/expenses/'.$report['id'].'/items/'.$itemId.'/receipt',
                ['file' => UploadedFile::fake()->image('sneaky.jpg')],
                ['Accept' => 'application/json'],
            )
            ->assertNotFound();
    }
}
