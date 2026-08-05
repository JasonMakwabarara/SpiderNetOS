<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Services\Spend\CategorySuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CategorySuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Suggestion Tenant',
            'slug' => 'suggest-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function seedMemory(Tenant $tenant, string $normalized, string $category, int $confirmCount): void
    {
        DB::table('merchant_category_map')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'merchant_normalized' => $normalized,
            'category' => $category,
            'chart_account_code' => '6420',
            'confirm_count' => $confirmCount,
            'last_confirmed_at' => now(),
            'source' => 'user_confirm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_merchant_memory_beats_keyword_rules(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $tenant = $this->createTenant();

        // "uber" would keyword-match travel; memory says office and must win.
        $this->seedMemory($tenant, 'uber', 'office', 3);

        $suggestion = app(CategorySuggestionService::class)
            ->suggest($tenant->id, 'Uber #4521 Inc.');

        $this->assertSame('office', $suggestion['category']);
        $this->assertSame('memory', $suggestion['source']);
        $this->assertSame('6420', $suggestion['chart_account_code']);
        // min(0.99, 0.7 + 0.05 * 3) = 0.85
        $this->assertEqualsWithDelta(0.85, $suggestion['confidence'], 0.001);
        $this->assertFalse($suggestion['requires_user_pick']);

        Http::assertNothingSent();
    }

    public function test_memory_confidence_caps_at_099(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $tenant = $this->createTenant();
        $this->seedMemory($tenant, 'megacorp', 'software', 50);

        $suggestion = app(CategorySuggestionService::class)
            ->suggest($tenant->id, 'MegaCorp LLC');

        $this->assertEqualsWithDelta(0.99, $suggestion['confidence'], 0.001);
    }

    public function test_keyword_rule_hit(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $tenant = $this->createTenant();

        $suggestion = app(CategorySuggestionService::class)
            ->suggest($tenant->id, "Joe's Coffee House");

        $this->assertSame('meals', $suggestion['category']);
        $this->assertSame('keyword', $suggestion['source']);
        $this->assertEqualsWithDelta(0.6, $suggestion['confidence'], 0.001);
        $this->assertNull($suggestion['chart_account_code']);
        // 0.6 < 0.75 threshold
        $this->assertTrue($suggestion['requires_user_pick']);

        Http::assertNothingSent();
    }

    public function test_llm_classify_fallback_includes_tenant_categories_in_enum(): void
    {
        Http::fake([
            '*/v1/classify' => Http::response([
                'category' => 'lab_equipment',
                'confidence' => 0.82,
                'model' => 'classifier-small',
                'cost_usd' => 0.0005,
            ]),
            '*' => Http::response([], 500),
        ]);

        $tenant = $this->createTenant();

        ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lab Equipment',
            'slug' => 'lab_equipment',
            'active' => true,
        ]);

        $suggestion = app(CategorySuggestionService::class)
            ->suggest($tenant->id, 'Zorbtron Industries', 'centrifuge rental', 480.00);

        $this->assertSame('lab_equipment', $suggestion['category']);
        $this->assertSame('llm', $suggestion['source']);
        $this->assertEqualsWithDelta(0.82, $suggestion['confidence'], 0.001);
        $this->assertFalse($suggestion['requires_user_pick']);

        Http::assertSent(function ($request) use ($tenant) {
            return str_contains($request->url(), '/v1/classify')
                && $request['tenant_id'] === $tenant->id
                && in_array('lab_equipment', $request['intent_enum'], true)
                && in_array('travel', $request['intent_enum'], true);
        });
    }

    public function test_classifier_answer_outside_enum_is_rejected(): void
    {
        Http::fake([
            '*/v1/classify' => Http::response(['category' => 'yachts', 'confidence' => 0.95]),
            '*' => Http::response([], 500),
        ]);

        $tenant = $this->createTenant();

        $suggestion = app(CategorySuggestionService::class)
            ->suggest($tenant->id, 'Zorbtron Industries');

        $this->assertSame('uncategorized', $suggestion['category']);
        $this->assertSame('fallback', $suggestion['source']);
    }

    public function test_total_failure_returns_uncategorized_fallback(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        $tenant = $this->createTenant();

        $suggestion = app(CategorySuggestionService::class)
            ->suggest($tenant->id, 'Zorbtron Industries');

        $this->assertSame('uncategorized', $suggestion['category']);
        $this->assertNull($suggestion['chart_account_code']);
        $this->assertEqualsWithDelta(0.3, $suggestion['confidence'], 0.001);
        $this->assertSame('fallback', $suggestion['source']);
        $this->assertTrue($suggestion['requires_user_pick']);
    }

    public function test_categorize_endpoint_returns_suggestion_payload(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $tenant = $this->createTenant();
        $user = \App\Models\User::create([
            'name' => 'Suggest User',
            'email' => 'suggest-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => 'member',
            'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/spend/categorize', [
                'merchant' => 'Delta Airlines',
                'description' => 'Flight to client site',
                'amount' => 412.10,
            ])
            ->assertOk()
            ->assertJsonPath('data.category', 'travel')
            ->assertJsonPath('data.source', 'keyword')
            ->assertJsonPath('data.requires_user_pick', true);
    }
}
