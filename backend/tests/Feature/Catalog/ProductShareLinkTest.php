<?php

namespace Tests\Feature\Catalog;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-056 Sprint P1 — authenticated CRUD for minting/listing/revoking an
// agent's own product-share link (mirrors AffiliateLinkTest's shape) +
// the PUBLIC landing/file endpoints (mirrors SalesMaterialShareLink tests'
// revoked/unknown-token boundary checks).
class ProductShareLinkTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
    }

    public function test_agent_can_mint_a_share_link_for_a_product(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/product-shares', ['product_id' => $product->id])
            ->assertCreated()
            ->assertJsonPath('data.agent_id', $agent->id)
            ->assertJsonPath('data.product_id', $product->id);
    }

    public function test_agent_without_basic_certification_cannot_mint_a_share_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        // Deliberately no passBasicCert() — BR-1 not satisfied.

        $this->actingAs($agent)
            ->postJson('/api/v1/product-shares', ['product_id' => $product->id])
            ->assertUnprocessable();
    }

    public function test_minting_twice_for_the_same_product_reuses_the_existing_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $first = $this->actingAs($agent)->postJson('/api/v1/product-shares', ['product_id' => $product->id])->assertCreated();
        // Reuse path returns the existing (not-recently-created) model, so
        // JsonResource's auto status code is 200, not 201 — this was a test
        // bug (asserted 201 on both calls, contradicting the method's own
        // "reuses the existing link" premise); ProductShareLinkService::create()
        // itself was already correct.
        $second = $this->actingAs($agent)->postJson('/api/v1/product-shares', ['product_id' => $product->id])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_agent_only_sees_their_own_share_links_in_index(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        ProductShareLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agentA->id]);
        ProductShareLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $response = $this->actingAs($agentA)->getJson('/api/v1/product-shares')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_agent_cannot_view_another_agents_share_link(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = ProductShareLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->getJson("/api/v1/product-shares/{$link->id}")
            ->assertForbidden();
    }

    public function test_company_admin_cannot_view_another_companys_share_link(): void
    {
        // BR-6 — cross-tenant guard, same shape as every other Policy in this app.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $link = ProductShareLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $agentA->id]);

        $this->actingAs($adminB)
            ->getJson("/api/v1/product-shares/{$link->id}")
            ->assertNotFound();
    }

    public function test_agent_can_revoke_their_own_share_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = ProductShareLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->deleteJson("/api/v1/product-shares/{$link->id}")
            ->assertNoContent();

        $this->assertNotNull($link->fresh()->revoked_at);
    }

    // ── Public landing page ────────────────────────────────────────────

    public function test_public_show_returns_product_context_and_increments_view_count(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'name' => 'Test Package']);
        $link = ProductShareLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id, 'product_id' => $product->id]);

        $response = $this->getJson("/api/v1/public/product-shares/{$link->token}")->assertOk();

        $response->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.product.name', 'Test Package')
            ->assertJsonPath('data.agent_name', $agent->name)
            ->assertJsonPath('data.company_name', $company->name);

        $this->assertSame(1, $link->fresh()->view_count);
    }

    public function test_public_show_never_exposes_internal_ids(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $link = ProductShareLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id, 'product_id' => $product->id]);

        $response = $this->getJson("/api/v1/public/product-shares/{$link->token}")->assertOk();

        $response->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('data.agent_id')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.view_count');
    }

    public function test_public_show_carries_the_sharing_agents_contact_channels(): void
    {
        // Human request 2026-08-21. A customer who had read the whole page
        // and wanted the product had nowhere to go: on a product whose
        // journey needs an appointment there is no buy bar, and the agent's
        // name was on the page as ATTRIBUTION rather than as a way to reach
        // the person selling to them.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'phone' => '0812345678',
            'email' => 'somchai@example.com',
        ]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);

        $this->getJson("/api/v1/public/product-shares/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.agent_phone', '0812345678')
            ->assertJsonPath('data.agent_email', 'somchai@example.com');
    }

    public function test_an_agent_with_no_phone_yields_null_rather_than_an_empty_string(): void
    {
        // An agent an Admin created may never have been given one, and the
        // page renders NO button for a null — an empty string would render a
        // live `tel:` that dials nothing.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'phone' => null]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);

        $this->getJson("/api/v1/public/product-shares/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.agent_phone', null);
    }

    public function test_the_contact_details_are_the_only_thing_widened(): void
    {
        // The two channels a customer needs to reply on, and nothing else.
        // This payload is unauthenticated: the agent's identity documents,
        // bank details and team must stay exactly as absent as they were.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => '1101700230708',
            'bank_account_number' => '1234567890',
            'bank_name' => 'กสิกรไทย',
        ]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);

        $body = $this->getJson("/api/v1/public/product-shares/{$link->token}")->assertOk()->getContent();

        $this->assertStringNotContainsString('1101700230708', $body);
        $this->assertStringNotContainsString('1234567890', $body);
        $this->assertStringNotContainsString('กสิกรไทย', $body);
    }

    public function test_public_show_404s_for_unknown_token(): void
    {
        $this->getJson('/api/v1/public/product-shares/not-a-real-token')->assertNotFound();
    }

    public function test_public_show_404s_for_revoked_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'revoked_at' => now(),
        ]);

        $this->getJson("/api/v1/public/product-shares/{$link->token}")->assertNotFound();
    }

    public function test_public_show_works_across_tenants_without_authentication(): void
    {
        // A guest visitor has no company context at all — confirms
        // TenantScope's no-op-for-guests path (see TenantScope::apply())
        // doesn't block the public lookup.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $product = Product::factory()->create(['company_id' => $companyA->id]);
        $link = ProductShareLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $agent->id, 'product_id' => $product->id]);

        // Sanity: companyB exists but is irrelevant — the lookup is by
        // token only, never scoped by any "current" company.
        $this->assertNotSame($companyA->id, $companyB->id);

        $this->getJson("/api/v1/public/product-shares/{$link->token}")->assertOk();
    }
}
