<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Jobs\ExtractSpendDocumentJob;
use App\Models\SpendDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Extraction Tenant',
            'slug' => 'extract-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role = 'member'): User
    {
        return User::create([
            'name' => 'Extraction User',
            'email' => $role.'-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }

    private function makeDocument(
        Tenant $tenant,
        User $user,
        string $contents,
        string $mime = 'image/jpeg',
        string $status = 'uploaded',
        ?array $extraction = null,
    ): SpendDocument {
        $path = "spend/{$tenant->id}/receipts/".Str::uuid().'.bin';
        Storage::disk('local')->put($path, $contents);

        return SpendDocument::create([
            'tenant_id' => $tenant->id,
            'uploaded_by' => $user->id,
            'kind' => 'receipt',
            'disk' => 'local',
            'path' => $path,
            'original_filename' => 'receipt.jpg',
            'mime_type' => $mime,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'status' => $status,
            'extraction' => $extraction,
        ]);
    }

    private function runJob(SpendDocument $doc): void
    {
        app()->call([new ExtractSpendDocumentJob($doc->id), 'handle']);
    }

    public function test_successful_llm_extraction_marks_document_extracted(): void
    {
        Storage::fake('local');

        Http::fake([
            '*/v1/extract-document' => Http::response([
                'fields' => [
                    'merchant' => ['value' => 'Uber BV', 'confidence' => 0.97],
                    'date' => ['value' => '2026-07-20', 'confidence' => 0.95],
                    'total' => ['value' => '42.50', 'confidence' => 0.96],
                    'tax' => ['value' => '3.20', 'confidence' => 0.9],
                    'currency' => ['value' => 'USD', 'confidence' => 0.99],
                ],
                'line_items' => [],
                'overall_confidence' => 0.95,
                'model' => 'qwen-vl-receipts',
                'cost_usd' => 0.008,
            ]),
            '*' => Http::response(['error' => 'unexpected call'], 500),
        ]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->makeDocument($tenant, $user, 'fake-image-bytes');

        $this->runJob($doc);

        $doc->refresh();

        $this->assertSame('extracted', $doc->status);
        $this->assertSame('llm', $doc->extraction_method);
        $this->assertSame('qwen-vl-receipts', $doc->extraction_model);
        $this->assertSame(1, $doc->attempts);
        $this->assertSame('Uber BV', $doc->extraction['fields']['merchant']['value']);
        $this->assertEqualsWithDelta(0.95, $doc->extraction['overall_confidence'], 0.001);
        $this->assertEqualsWithDelta(0.008, (float) $doc->extraction_cost_usd, 0.0001);

        // Inline category suggestion: "uber" keyword rule => travel.
        $this->assertSame('travel', $doc->extraction['suggested_category']['category']);
        $this->assertSame('keyword', $doc->extraction['suggested_category']['source']);

        $this->assertDatabaseHas('event_log', [
            'event_type' => 'spend_document.extracted',
            'aggregate_id' => $doc->id,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/extract-document')
            && $request['tenant_id'] === $tenant->id
            && $request['mode'] === 'image');
    }

    public function test_inference_failure_falls_back_to_heuristics_when_text_available(): void
    {
        Storage::fake('local');

        Http::fake([
            '*/v1/extract-document' => Http::response(['error' => 'boom'], 500),
            '*/v1/classify' => Http::response(['category' => 'meals', 'confidence' => 0.88]),
            '*' => Http::response([], 500),
        ]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $text = "STARBUCKS #4521\nJan 15, 2026\nCoffee 3.50\nTax 3.20\nTotal: \$42.50\n";
        $doc = $this->makeDocument($tenant, $user, $text, 'text/plain');

        $this->runJob($doc);

        $doc->refresh();

        // Heuristic confidences cap at 0.4 < review_threshold 0.6.
        $this->assertSame('needs_review', $doc->status);
        $this->assertSame('heuristic', $doc->extraction_method);
        $this->assertSame('42.50', $doc->extraction['fields']['total']['value']);
        $this->assertSame('STARBUCKS #4521', $doc->extraction['fields']['merchant']['value']);
        $this->assertSame('2026-01-15', $doc->extraction['fields']['date']['value']);
        $this->assertSame('USD', $doc->extraction['fields']['currency']['value']);
        $this->assertLessThanOrEqual(0.4, $doc->extraction['overall_confidence']);

        foreach ($doc->extraction['fields'] as $field) {
            $this->assertLessThanOrEqual(0.4, $field['confidence']);
        }

        // Suggestion cascaded to the classifier (no memory row, no keyword hit).
        $this->assertSame('meals', $doc->extraction['suggested_category']['category']);
        $this->assertSame('llm', $doc->extraction['suggested_category']['source']);

        $this->assertDatabaseHas('event_log', [
            'event_type' => 'spend_document.extracted',
            'aggregate_id' => $doc->id,
        ]);
    }

    public function test_low_confidence_llm_extraction_lands_in_needs_review(): void
    {
        Storage::fake('local');

        Http::fake([
            '*/v1/extract-document' => Http::response([
                'fields' => [
                    'merchant' => ['value' => 'Blurry Mart', 'confidence' => 0.4],
                    'total' => ['value' => '9.99', 'confidence' => 0.3],
                ],
                'overall_confidence' => 0.35,
                'model' => 'qwen-vl-receipts',
                'cost_usd' => 0.008,
            ]),
            '*' => Http::response([], 500),
        ]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->makeDocument($tenant, $user, 'blurry-image-bytes');

        $this->runJob($doc);

        $doc->refresh();

        $this->assertSame('needs_review', $doc->status);
        $this->assertSame('llm', $doc->extraction_method);
        $this->assertEqualsWithDelta(0.35, $doc->extraction['overall_confidence'], 0.001);
    }

    public function test_image_without_inference_or_text_goes_to_needs_review(): void
    {
        Storage::fake('local');

        Http::fake(['*' => Http::response([], 500)]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->makeDocument($tenant, $user, 'opaque-image-bytes');

        $this->runJob($doc);

        $doc->refresh();

        $this->assertSame('needs_review', $doc->status);
        $this->assertNull($doc->extraction_method);
        $this->assertNotNull($doc->error);
    }

    public function test_confirm_endpoint_updates_status_and_learns_merchant_category(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response([], 500)]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $extraction = [
            'fields' => [
                'merchant' => ['value' => 'Starbucks #221', 'confidence' => 0.9],
                'total' => ['value' => '18.20', 'confidence' => 0.9],
            ],
            'overall_confidence' => 0.9,
        ];

        $doc = $this->makeDocument($tenant, $user, 'receipt-one', 'image/jpeg', 'extracted', $extraction);

        $payload = [
            'merchant' => 'Starbucks #221',
            'date' => '2026-07-20',
            'total' => 18.20,
            'tax' => 1.40,
            'currency' => 'USD',
            'category' => 'meals',
        ];

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/spend/documents/'.$doc->id.'/confirm', $payload)
            ->assertOk();

        $doc->refresh();
        $this->assertSame('confirmed', $doc->status);
        $this->assertSame($user->id, $doc->confirmed_by);
        $this->assertNotNull($doc->confirmed_at);

        $this->assertDatabaseHas('event_log', [
            'event_type' => 'spend_document.confirmed',
            'aggregate_id' => $doc->id,
        ]);

        // Projection learned the mapping (store number stripped).
        $this->assertDatabaseHas('merchant_category_map', [
            'tenant_id' => $tenant->id,
            'merchant_normalized' => 'starbucks',
            'category' => 'meals',
            'confirm_count' => 1,
            'source' => 'user_confirm',
        ]);

        // Second confirmation of the same merchant + category bumps the count.
        $doc2 = $this->makeDocument($tenant, $user, 'receipt-two', 'image/jpeg', 'extracted', $extraction);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/spend/documents/'.$doc2->id.'/confirm', $payload)
            ->assertOk();

        $this->assertDatabaseHas('merchant_category_map', [
            'tenant_id' => $tenant->id,
            'merchant_normalized' => 'starbucks',
            'category' => 'meals',
            'confirm_count' => 2,
        ]);

        // A different category overwrites and resets the counter.
        $doc3 = $this->makeDocument($tenant, $user, 'receipt-three', 'image/jpeg', 'extracted', $extraction);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/spend/documents/'.$doc3->id.'/confirm', array_merge($payload, ['category' => 'office']))
            ->assertOk();

        $this->assertDatabaseHas('merchant_category_map', [
            'tenant_id' => $tenant->id,
            'merchant_normalized' => 'starbucks',
            'category' => 'office',
            'confirm_count' => 1,
        ]);

        // Confirming an already-confirmed document conflicts.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/spend/documents/'.$doc->id.'/confirm', $payload)
            ->assertStatus(409);
    }

    public function test_retry_endpoint_requeues_failed_documents_only(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $failed = $this->makeDocument($tenant, $user, 'failed-bytes', 'image/jpeg', 'failed');
        $extracted = $this->makeDocument($tenant, $user, 'ok-bytes', 'image/jpeg', 'extracted');

        Queue::fake();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/spend/documents/'.$failed->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(ExtractSpendDocumentJob::class, fn ($job) => $job->documentId === $failed->id);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/spend/documents/'.$extracted->id.'/retry')
            ->assertStatus(409);
    }

    public function test_show_is_tenant_scoped(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $otherTenant = $this->createTenant();
        $otherUser = $this->createUser($otherTenant);

        $doc = $this->makeDocument($tenant, $user, 'scoped-bytes');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/spend/documents/'.$doc->id)
            ->assertOk()
            ->assertJsonPath('data.id', $doc->id);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/financial/spend/documents/'.$doc->id)
            ->assertNotFound();
    }
}
