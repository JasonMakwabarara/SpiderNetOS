<?php

declare(strict_types=1);

namespace Tests\Feature\Hannah;

use App\Models\BrainFile;
use App\Models\HannahLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hannah\HannahApiException;
use App\Services\Hannah\HannahBrandMapper;
use App\Services\Hannah\HannahHandoffService;
use App\Services\Hannah\HannahNotConfiguredException;
use App\Services\Hannah\HannahPartnerToken;
use App\Services\TenantKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "I need to market this new product" (plan D7 §1): provisioning, the brand
 * mapped out of the Knowledge brain, the single-use deep link, and the
 * boundaries that keep the hand-off honest.
 */
class HannahHandoffTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private string $keyPath;

    /** @var list<HttpRequest> */
    private array $sent = [];

    /** @var array<string, mixed>  per-test endpoint overrides */
    private array $hannahOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Northbeam Books', 'slug' => 'northbeam-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $this->admin = User::create([
            'name' => 'Thandi', 'email' => 't-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->keyPath = $this->writeSigningKey();

        config()->set('services.hannah.url', 'https://hannah.test');
        config()->set('services.hannah.signing_key_path', $this->keyPath);
        $this->flags(['hannah_ai.handoff' => 'on']);
        $this->fakeHannah();
    }

    protected function tearDown(): void
    {
        if (isset($this->keyPath) && $this->keyPath !== '') {
            @unlink($this->keyPath);
        }
        parent::tearDown();
    }

    /**
     * A throwaway RSA key for the partner assertion.
     *
     * No key is committed to the repo — a test fixture private key is still a
     * private key in git history, and this is the exact key type the leaked
     * `.pem.txt` pair was. Generated fresh per test and deleted in tearDown.
     *
     * openssl_pkey_new() needs a readable openssl.cnf, which some Windows PHP
     * builds point at a path that does not exist; the CLI fallback keeps the
     * suite runnable there as well as on CI.
     */
    private function writeSigningKey(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hannah-partner-'.bin2hex(random_bytes(6)).'.pem';

        $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key !== false && @openssl_pkey_export($key, $pem)) {
            file_put_contents($path, $pem);

            return $path;
        }

        while (openssl_error_string()) {
            // Drain the queue so a later openssl call is not blamed for this one.
        }

        $devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pem = shell_exec('openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 2>'.$devNull);
        if (is_string($pem) && str_contains($pem, 'PRIVATE KEY')) {
            file_put_contents($path, $pem);

            return $path;
        }

        $this->markTestSkipped('No usable OpenSSL on this machine: openssl_pkey_new failed and the openssl CLI is absent.');
    }

    private function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    /**
     * Http::fake() accumulates stubs rather than replacing them and the first
     * registered match wins, so a test cannot simply re-fake over setUp's
     * stub. One stub is registered, once, and each handler consults
     * $this->hannahOverrides — which a test can swap at any point.
     */
    private function hannahReplies(array $overrides): void
    {
        $this->hannahOverrides = $overrides;
    }

    private function fakeHannah(array $overrides = []): void
    {
        $this->sent = [];
        $this->hannahOverrides = $overrides;

        Http::fake([
            'hannah.test/api/partner/token' => function (HttpRequest $request) {
                $this->sent[] = $request;

                $override = $this->override('hannah.test/api/partner/token');
                if ($override !== null) {
                    return is_callable($override) ? $override($request) : $override;
                }

                return Http::response(['access_token' => 'hannah-bearer-'.substr(sha1((string) $request->data()['assertion']), 0, 8), 'expires_in' => 900]);
            },
            'hannah.test/api/partner/users' => function (HttpRequest $request) {
                $this->sent[] = $request;

                $override = $this->override('hannah.test/api/partner/users');
                if ($override !== null) {
                    return is_callable($override) ? $override($request) : $override;
                }

                return Http::response([
                    'user_id' => 'hu_9001', 'workspace_id' => 'hw_5001', 'company_id' => 'hc_3001',
                    'open_id' => 'open_abc', 'webhook_secret' => 'whsec_supersecret',
                ], 201);
            },
            'hannah.test/api/partner/users/*/brand' => function (HttpRequest $request) {
                $this->sent[] = $request;

                $override = $this->override('hannah.test/api/partner/users/*/brand');
                if ($override !== null) {
                    return is_callable($override) ? $override($request) : $override;
                }

                return Http::response(['company_id' => 'hc_3001', 'updated' => true]);
            },
            'hannah.test/api/partner/deep-link' => function (HttpRequest $request) {
                $this->sent[] = $request;

                $override = $this->override('hannah.test/api/partner/deep-link');
                if ($override !== null) {
                    return is_callable($override) ? $override($request) : $override;
                }

                return Http::response([
                    'url' => 'https://hannah.test/auth/partner?token=one-time-abc&redirect='.urlencode((string) $request->data()['redirect']),
                    'expires_in' => 60,
                ]);
            },
        ]);
    }

    /** The override for this endpoint, if a test set one. */
    private function override(string $key): mixed
    {
        return $this->hannahOverrides[$key] ?? null;
    }

    private function brainFile(string $path, array $frontmatter, string $content): void
    {
        BrainFile::create([
            'tenant_id' => $this->tenant->id, 'path' => $path, 'title' => $path,
            'content' => $content, 'frontmatter' => $frontmatter, 'source' => 'human',
            'data_class' => 'internal', 'version' => 1, 'content_hash' => hash('sha256', $content),
        ]);
    }

    /** A brain filled in the way a real tenant's would be. */
    private function fillBrain(): void
    {
        $this->brainFile('brand/voice.md', [
            'brand' => 'Northbeam Books',
            'website' => 'https://northbeam.example',
            'tagline' => 'Your books, closed by the 5th.',
            'one_liner' => 'Bookkeeping for independent cafés that closes the month before the month gets away.',
            'industry' => 'bookkeeping',
            'tone' => ['warm', 'direct', 'slightly dry'],
            'palette' => ['charge' => '#F26430', 'ink' => '#111111'],
            'logo' => 'https://northbeam.example/logo.svg',
        ], "## Tone\nWarm, direct, slightly dry. Short sentences.\n\n## Do and don't\nDon't say \"leverage\" or \"seamless\". Never exclamation marks.\n\n## Voice\nWe sound like a good bookkeeper who has seen your kind of mess before.\n");

        $this->brainFile('business/profile.md', ['name' => 'Northbeam Books', 'industry' => 'bookkeeping'],
            "## What we do\nBookkeeping for independent cafés in Cape Town.\n\n## What makes us different\nWe close by the 5th or the month is free.\n");

        $this->brainFile('customers/icp.md', ['segments' => ['cafés']],
            "## Who we sell to\nOwner-operated cafés with one to three sites and no in-house finance person.\n");

        $this->brainFile('offer/offer.md', [
            'products' => ['Monthly bookkeeping', 'VAT returns'],
            'pricing' => 'From R1,800 per month',
        ], "## Products and services\nMonthly bookkeeping, VAT returns and a one-page profit summary.\n");

        $this->brainFile('people/user.md', ['name' => 'Thandi'],
            "## Who I am\nThandi, founder.\n\n## Never say or offer\nNever offer a discount. Never name a competitor.\n");
    }

    private function handoff(): HannahHandoffService
    {
        return app(HannahHandoffService::class);
    }

    private function tenantId(): string
    {
        return (string) $this->tenant->id;
    }

    // ------------------------------------------------------------------ //
    //  The brand mapper — the point of the whole hand-off
    // ------------------------------------------------------------------ //

    public function test_the_brain_becomes_a_company_payload_without_inventing_anything(): void
    {
        $this->fillBrain();

        $mapped = app(HannahBrandMapper::class)->map($this->tenantId());
        $payload = $mapped['payload'];

        $this->assertSame('Northbeam Books', $payload['name']);
        $this->assertSame('https://northbeam.example', $payload['website']);
        $this->assertSame('Your books, closed by the 5th.', $payload['tagline']);
        $this->assertSame('bookkeeping', $payload['industry']);
        $this->assertSame('#F26430', $payload['brand_color']);

        $this->assertStringContainsString('closes the month before the month gets away', $payload['description']);
        $this->assertStringContainsString('We close by the 5th', $payload['description']);

        $this->assertStringContainsString('warm, direct, slightly dry', $payload['tone_of_voice']);
        $this->assertStringContainsString('good bookkeeper', $payload['tone_of_voice']);

        $this->assertStringContainsString('Owner-operated cafés', $payload['target_audience']);

        // The prohibitions travel with the brand: Hannah is the product that
        // actually publishes, so it is the one that must know them.
        $this->assertStringContainsString('Never offer a discount', $payload['specific_instructions']);
        $this->assertStringContainsString('seamless', $payload['specific_instructions']);

        $this->assertSame(['Monthly bookkeeping', 'VAT returns'], array_column($payload['products'], 'name'));
        $this->assertSame('From R1,800 per month', $payload['products'][0]['pricing']);
        $this->assertSame([['kind' => 'logo', 'url' => 'https://northbeam.example/logo.svg']], $payload['assets']);

        // brand/identity.md was never written, and the mapper says so rather
        // than filling the gap.
        $this->assertContains('brand/identity.md', $mapped['missing']);
    }

    public function test_an_empty_brain_maps_to_nothing_rather_than_to_guesses(): void
    {
        $mapped = app(HannahBrandMapper::class)->map($this->tenantId());

        $this->assertSame([], $mapped['payload']);
        $this->assertSame(HannahBrandMapper::SOURCE_PATHS, $mapped['missing']);

        $readiness = app(HannahBrandMapper::class)->readiness($this->tenantId());
        $this->assertFalse($readiness['ready']);
        $this->assertSame(['name', 'description', 'tone_of_voice'], $readiness['missing_fields']);
    }

    public function test_the_hash_tracks_the_payload_not_the_files(): void
    {
        $this->fillBrain();
        $mapper = app(HannahBrandMapper::class);
        $before = $mapper->map($this->tenantId())['hash'];

        // A brain edit Hannah cannot see must not change the hash.
        $file = BrainFile::forTenant($this->tenantId())->where('path', 'people/user.md')->firstOrFail();
        $file->forceFill(['content' => $file->content."\n## Calendar and meetings\nTuesdays are for the books.\n"])->save();

        $this->assertSame($before, $mapper->map($this->tenantId())['hash']);

        // One Hannah can see does.
        $voice = BrainFile::forTenant($this->tenantId())->where('path', 'brand/voice.md')->firstOrFail();
        $voice->forceFill(['frontmatter' => array_merge($voice->frontmatter, ['tagline' => 'Closed by the 5th, every month.'])])->save();

        $this->assertNotSame($before, $mapper->map($this->tenantId())['hash']);
    }

    // ------------------------------------------------------------------ //
    //  The partner assertion
    // ------------------------------------------------------------------ //

    public function test_the_partner_assertion_is_short_lived_single_use_and_rs256(): void
    {
        $tokens = app(HannahPartnerToken::class);
        $this->assertTrue($tokens->configured());

        $jwt = $tokens->assertionFor($this->tenantId());
        [$header, , $signature] = explode('.', $jwt);

        $decodedHeader = json_decode(base64_decode(strtr($header, '-_', '+/'), true), true);
        $this->assertSame('RS256', $decodedHeader['alg']);
        $this->assertSame('spidernet-partner-1', $decodedHeader['kid']);

        $claims = HannahPartnerToken::peek($jwt);
        $this->assertSame('spidernet', $claims['iss']);
        $this->assertSame('hannah-ai', $claims['aud']);
        $this->assertSame('tenant:'.$this->tenantId(), $claims['sub']);
        $this->assertSame(300, $claims['exp'] - $claims['iat']);
        $this->assertTrue(Str::isUuid($claims['jti']));

        // Single use: a second assertion is a different token.
        $this->assertNotSame($claims['jti'], HannahPartnerToken::peek($tokens->assertionFor($this->tenantId()))['jti']);

        // The signature verifies against the public half, and nothing else.
        $public = openssl_pkey_get_details(openssl_pkey_get_private((string) file_get_contents($this->keyPath)))['key'];
        $signingInput = implode('.', array_slice(explode('.', $jwt), 0, 2));
        $raw = base64_decode(strtr($signature, '-_', '+/'), true);
        $this->assertSame(1, openssl_verify($signingInput, $raw, $public, OPENSSL_ALGO_SHA256));
        $this->assertSame(0, openssl_verify($signingInput.'x', $raw, $public, OPENSSL_ALGO_SHA256));
    }

    public function test_without_a_signing_key_the_hand_off_says_so_instead_of_failing_obscurely(): void
    {
        config()->set('services.hannah.signing_key_path', '');

        $this->assertFalse(app(HannahPartnerToken::class)->configured());
        $this->expectException(HannahNotConfiguredException::class);
        app(HannahPartnerToken::class)->assertionFor($this->tenantId());
    }

    // ------------------------------------------------------------------ //
    //  Linking
    // ------------------------------------------------------------------ //

    public function test_linking_provisions_one_workspace_and_carries_the_brand_with_it(): void
    {
        $this->fillBrain();

        $link = $this->handoff()->link($this->tenantId(), $this->admin, true);

        $this->assertSame(HannahLink::STATUS_LINKED, $link->status);
        $this->assertSame(['hu_9001', 'hw_5001', 'hc_3001', 'open_abc'],
            [$link->hannah_user_id, $link->hannah_workspace_id, $link->hannah_company_id, $link->open_id]);
        $this->assertSame($this->admin->email, $link->owner_email);
        $this->assertNotNull($link->terms_accepted_at);

        // The Company was created from the brain, so nothing has to be typed twice.
        $provision = collect($this->sent)->first(fn (HttpRequest $r): bool => str_ends_with($r->url(), '/api/partner/users'));
        $this->assertNotNull($provision);
        $this->assertSame('Northbeam Books', $provision->data()['company']['name']);
        $this->assertStringContainsString('Never offer a discount', $provision->data()['company']['specific_instructions']);
        $this->assertSame($this->tenantId(), $provision->data()['external_id']);
        $this->assertSame('Bearer hannah-bearer-'.substr(sha1((string) collect($this->sent)->first()->data()['assertion']), 0, 8),
            $provision->header('Authorization')[0]);
        $this->assertNotEmpty($provision->header('X-Request-Id'));

        // Already synced at link time: no second push for the same payload.
        $this->assertNotNull($link->last_brand_synced_at);
        $this->assertSame('unchanged', $this->handoff()->syncBrand($this->tenantId())['reason']);

        // The webhook secret is in the key manager, never in the row.
        $this->assertSame('hannah:webhook:'.$this->tenantId(), $link->webhook_secret_ref);
        $this->assertSame('whsec_supersecret', app(TenantKeyManager::class)->getSecret($this->tenantId(), $link->webhook_secret_ref));
        $this->assertArrayNotHasKey('webhook_secret_ref', $link->toArray());
    }

    public function test_linking_twice_does_not_create_a_second_hannah_account(): void
    {
        $this->fillBrain();

        $first = $this->handoff()->link($this->tenantId(), $this->admin, true);
        $second = $this->handoff()->link($this->tenantId(), $this->admin, true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, HannahLink::count());
        $this->assertCount(1, collect($this->sent)->filter(fn (HttpRequest $r): bool => str_ends_with($r->url(), '/api/partner/users')));
    }

    public function test_an_account_is_never_created_without_the_owner_accepting_the_terms(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->handoff()->link($this->tenantId(), $this->admin, false);
        } finally {
            $this->assertSame([], $this->sent);
        }
    }

    public function test_a_refusal_from_hannah_is_recorded_on_the_link_rather_than_swallowed(): void
    {
        $this->hannahReplies(['hannah.test/api/partner/users' => Http::response(['message' => 'email already claimed'], 422)]);

        $caught = null;
        try {
            $this->handoff()->link($this->tenantId(), $this->admin, true);
        } catch (HannahApiException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'The hand-off did not surface the refusal.');
        $this->assertSame(422, $caught->status);
        $this->assertStringContainsString('email already claimed', $caught->getMessage());

        $link = HannahLink::forTenant($this->tenantId())->firstOrFail();
        $this->assertSame(HannahLink::STATUS_FAILED, $link->status);
        $this->assertStringContainsString('email already claimed', (string) $link->error);
        $this->assertFalse($link->isLinked());
    }

    // ------------------------------------------------------------------ //
    //  Brand sync
    // ------------------------------------------------------------------ //

    public function test_a_sync_is_skipped_when_nothing_hannah_can_see_has_changed(): void
    {
        $this->fillBrain();
        $this->handoff()->link($this->tenantId(), $this->admin, true);

        $this->assertSame('unchanged', $this->handoff()->syncBrand($this->tenantId())['reason']);
        $this->assertCount(0, collect($this->sent)->filter(fn (HttpRequest $r): bool => str_contains($r->url(), '/brand')));

        // Forced, it goes anyway.
        $this->assertTrue($this->handoff()->syncBrand($this->tenantId(), force: true)['synced']);

        // A real change goes without forcing.
        $voice = BrainFile::forTenant($this->tenantId())->where('path', 'brand/voice.md')->firstOrFail();
        $voice->forceFill(['frontmatter' => array_merge($voice->frontmatter, ['tagline' => 'Closed by the 5th.'])])->save();

        $result = $this->handoff()->syncBrand($this->tenantId());
        $this->assertTrue($result['synced']);

        $push = collect($this->sent)->last(fn (HttpRequest $r): bool => str_contains($r->url(), '/brand'));
        $this->assertSame('https://hannah.test/api/partner/users/hu_9001/brand', $push->url());
        $this->assertSame('Closed by the 5th.', $push->data()['tagline']);
    }

    public function test_an_unlinked_workspace_syncs_nothing(): void
    {
        $this->fillBrain();

        $this->assertSame(
            ['synced' => false, 'reason' => 'not_linked'],
            Arr::only($this->handoff()->syncBrand($this->tenantId()), ['synced', 'reason']),
        );
        $this->assertSame([], $this->sent);
    }

    // ------------------------------------------------------------------ //
    //  The deep link
    // ------------------------------------------------------------------ //

    public function test_the_deep_link_lands_the_owner_inside_hannah_and_refuses_an_open_redirect(): void
    {
        $this->fillBrain();
        $this->handoff()->link($this->tenantId(), $this->admin, true);

        $link = $this->handoff()->deepLink($this->tenantId(), '/campaigns/new');
        $this->assertStringStartsWith('https://hannah.test/auth/partner?token=', $link['url']);
        $this->assertStringContainsString(urlencode('/campaigns/new'), $link['url']);
        $this->assertSame(60, $link['expires_in']);

        // A signed-in session on another product is exactly what an open
        // redirect would hand an attacker.
        foreach (['https://evil.example', '//evil.example', '/\\evil.example', 'javascript:alert(1)', '/x'."\n".'Location: https://evil.example'] as $hostile) {
            $this->assertSame('/', HannahHandoffService::safeRedirect($hostile), $hostile);
        }
        $this->assertSame('/campaigns/new', HannahHandoffService::safeRedirect('/campaigns/new'));
    }

    // ------------------------------------------------------------------ //
    //  API
    // ------------------------------------------------------------------ //

    public function test_the_api_reports_status_links_syncs_and_deep_links(): void
    {
        $this->fillBrain();

        $status = $this->actingAs($this->admin, 'sanctum')->getJson('/api/hannah/link')->assertOk();
        $this->assertFalse($status->json('data.linked'));
        $this->assertSame('unlinked', $status->json('data.status'));
        $this->assertTrue($status->json('data.brand.ready'));
        $this->assertTrue($status->json('data.configured'));

        // The terms are a required, explicit act.
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/hannah/link', [])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/hannah/link', ['accept_terms' => false])->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/hannah/link', ['accept_terms' => true])
            ->assertCreated()->assertJsonPath('data.linked', true);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/hannah/brand/sync')
            ->assertOk()->assertJsonPath('data.reason', 'unchanged');

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/hannah/deep-link?to=/campaigns/new')
            ->assertOk()->assertJsonPath('data.expires_in', 60);
    }

    public function test_linking_is_admin_only_and_every_route_needs_authentication(): void
    {
        $member = User::create([
            'name' => 'Member', 'email' => 'm-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'member', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($member, 'sanctum')->postJson('/api/hannah/link', ['accept_terms' => true])->assertForbidden();
        $this->actingAs($member, 'sanctum')->postJson('/api/hannah/brand/sync')->assertForbidden();
        // Reading the status is not privileged.
        $this->actingAs($member, 'sanctum')->getJson('/api/hannah/link')->assertOk();
    }

    /**
     * Its own test: actingAs() authenticates for the remainder of a method, so
     * asserting 401 after an authenticated call would only ever read back the
     * still-authenticated session.
     */
    public function test_the_hand_off_routes_require_authentication(): void
    {
        $this->getJson('/api/hannah/link')->assertUnauthorized();
        $this->postJson('/api/hannah/link', ['accept_terms' => true])->assertUnauthorized();
        $this->postJson('/api/hannah/brand/sync')->assertUnauthorized();
        $this->getJson('/api/hannah/deep-link')->assertUnauthorized();
    }

    public function test_the_flag_gates_the_hand_off(): void
    {
        $this->flags(['hannah_ai.handoff' => 'off']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/hannah/link', ['accept_terms' => true])
            ->assertStatus(409)->assertJsonPath('reason', 'hannah_not_configured');
        $this->assertSame([], $this->sent);
    }

    public function test_one_tenants_link_is_not_anothers(): void
    {
        $this->fillBrain();
        $this->handoff()->link($this->tenantId(), $this->admin, true);

        $other = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other Co', 'slug' => 'other-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $otherAdmin = User::create([
            'name' => 'Other', 'email' => 'o-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $other->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($otherAdmin, 'sanctum')->getJson('/api/hannah/link')
            ->assertOk()->assertJsonPath('data.linked', false)->assertJsonPath('data.status', 'unlinked');

        $this->actingAs($otherAdmin, 'sanctum')->getJson('/api/hannah/deep-link')
            ->assertStatus(409)->assertJsonPath('reason', 'not_linked');
    }
}
