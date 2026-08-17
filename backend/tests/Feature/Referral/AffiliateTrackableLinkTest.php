<?php

namespace Tests\Feature\Referral;

use App\Models\AffiliateAttributionSetting;
use App\Models\AffiliateLink;
use App\Models\AffiliateLinkClick;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011 Section 4 (TASK-032) — the two PUBLIC, unauthenticated routes:
// GET /api/v1/l/{token} (click log + redirect) and
// POST /api/v1/public/affiliate-leads/{token} (lead capture). First
// unauthenticated write surface in this codebase — tested with the same
// rigor as any authenticated endpoint, per CLAUDE.md Section 6, plus the
// honeypot/rate-limit/enumeration-resistance concerns unique to being
// public.
class AffiliateTrackableLinkTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
    }

    private function validLeadPayload(Product $product): array
    {
        return [
            'name' => 'Somchai Testcase',
            'phone' => '0812345678',
            'branch' => 'Silom',
            'product_id' => $product->id,
            'consent' => true,
        ];
    }

    public function test_click_is_logged_and_redirects_to_the_frontend_landing_page(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $response = $this->get("/api/v1/l/{$link->token}");

        $response->assertRedirect();
        $this->assertStringContainsString("/l/{$link->token}", $response->headers->get('Location'));
        $this->assertSame(1, AffiliateLinkClick::where('link_id', $link->id)->count());
    }

    public function test_click_stores_a_hashed_ip_never_the_raw_ip(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $this->get("/api/v1/l/{$link->token}", ['REMOTE_ADDR' => '203.0.113.42']);

        $click = AffiliateLinkClick::where('link_id', $link->id)->firstOrFail();
        $this->assertNotSame('203.0.113.42', $click->ip_hash);
        $this->assertSame(64, strlen($click->ip_hash)); // sha256 hex digest length
    }

    public function test_invalid_token_returns_404_on_all_three_public_routes(): void
    {
        $this->get('/api/v1/l/not-a-real-token')->assertNotFound();
        $this->getJson('/api/v1/public/affiliate-leads/not-a-real-token')->assertNotFound();
        $this->postJson('/api/v1/public/affiliate-leads/not-a-real-token', ['name' => 'x'])->assertNotFound();
    }

    // TASK-033 gap-fill — GET /public/affiliate-leads/{token}, the
    // landing page's "what am I looking at" call before it ever shows
    // the form. See PublicAffiliateLinkContextResource for the exact
    // field boundary this test locks in.
    public function test_link_context_shows_the_fixed_product_when_the_link_is_scoped_to_one(): void
    {
        $company = Company::factory()->create(['name' => 'Thai Life Test Co']);
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'first_name' => 'Somsri', 'last_name' => 'Agent']);
        $product = Product::factory()->create(['company_id' => $company->id, 'name' => 'Health Package 8900', 'price_satang' => 890000]);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id, 'product_id' => $product->id]);

        $response = $this->getJson("/api/v1/public/affiliate-leads/{$link->token}");

        $response->assertOk()
            ->assertJsonPath('data.company_name', 'Thai Life Test Co')
            ->assertJsonPath('data.agent_name', 'Somsri Agent')
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.product.name', 'Health Package 8900')
            ->assertJsonPath('data.product.price_satang', 890000)
            ->assertJsonPath('data.products', null)
            ->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('data.agent_id')
            ->assertJsonMissingPath('data.token');
    }

    public function test_link_context_lists_active_products_when_the_link_has_no_fixed_product(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id, 'product_id' => null]);
        $activeProduct = Product::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        Product::factory()->create(['company_id' => $company->id, 'is_active' => false]);
        // Tenant isolation — a product from a different company must
        // never leak into this list just because it's also "active".
        Product::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);

        $response = $this->getJson("/api/v1/public/affiliate-leads/{$link->token}");

        $response->assertOk()->assertJsonPath('data.product', null);
        $ids = collect($response->json('data.products'))->pluck('id')->all();
        $this->assertSame([$activeProduct->id], $ids);
    }

    public function test_lead_capture_within_attribution_window_creates_an_attributed_referral(): void
    {
        $company = Company::factory()->create();
        AffiliateAttributionSetting::factory()->create(['company_id' => $company->id, 'attribution_window_days' => 7]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->get("/api/v1/l/{$link->token}"); // logs a click "now"

        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", $this->validLeadPayload($product))
            ->assertOk();

        $referral = Referral::where('agent_id', $agent->id)->firstOrFail();
        $this->assertSame($link->id, $referral->affiliate_link_id);
    }

    public function test_lead_capture_outside_attribution_window_still_captures_the_lead_but_does_not_attribute(): void
    {
        $company = Company::factory()->create();
        AffiliateAttributionSetting::factory()->create(['company_id' => $company->id, 'attribution_window_days' => 7]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        AffiliateLinkClick::factory()->create([
            'company_id' => $company->id, 'link_id' => $link->id, 'clicked_at' => now()->subDays(30),
        ]);

        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", $this->validLeadPayload($product))
            ->assertOk();

        // The lead is still captured (never dropped just because
        // attribution expired), just not credited as an attributed
        // conversion.
        $referral = Referral::where('agent_id', $agent->id)->firstOrFail();
        $this->assertNull($referral->affiliate_link_id);
    }

    public function test_lead_capture_with_no_click_at_all_still_captures_the_lead_unattributed(): void
    {
        $company = Company::factory()->create();
        AffiliateAttributionSetting::factory()->create(['company_id' => $company->id, 'attribution_window_days' => 7]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", $this->validLeadPayload($product))
            ->assertOk();

        $referral = Referral::where('agent_id', $agent->id)->firstOrFail();
        $this->assertNull($referral->affiliate_link_id);
    }

    public function test_honeypot_field_silently_rejects_without_creating_anything(): void
    {
        $company = Company::factory()->create();
        AffiliateAttributionSetting::factory()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $payload = array_merge($this->validLeadPayload($product), ['hp_field' => 'i-am-a-bot']);

        $response = $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", $payload);

        // Same success-shaped response a genuine submission gets — a
        // bot must never learn it was caught.
        $response->assertOk();
        $this->assertSame(0, Referral::where('agent_id', $agent->id)->count());
    }

    public function test_lead_capture_is_rejected_when_the_agent_has_not_passed_basic_certification(): void
    {
        $company = Company::factory()->create();
        AffiliateAttributionSetting::factory()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        // Deliberately no passBasicCert() call — BR-1 not satisfied.
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", $this->validLeadPayload($product))
            ->assertUnprocessable();

        $this->assertSame(0, Referral::where('agent_id', $agent->id)->count());
    }

    public function test_lead_capture_endpoint_is_rate_limited(): void
    {
        $company = Company::factory()->create();
        AffiliateAttributionSetting::factory()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", $this->validLeadPayload($product));
        }

        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", $this->validLeadPayload($product))
            ->assertStatus(429);
    }

    // TASK-034 QA gap-fill — input-validation fuzzing beyond plain
    // required-field checks: a hostile/malformed public submission must
    // 422 cleanly (StoreAffiliateLeadRequest's Form Request validation),
    // never 500, and must never let a product from a DIFFERENT company
    // attach to this link's referral (the Rule::exists()->where('company_id', ...)
    // scoping on product_id).
    public function test_lead_capture_rejects_malformed_and_cross_tenant_input(): void
    {
        $company = Company::factory()->create();
        AffiliateAttributionSetting::factory()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);
        $ownProduct = Product::factory()->create(['company_id' => $company->id]);

        $otherCompany = Company::factory()->create();
        $foreignProduct = Product::factory()->create(['company_id' => $otherCompany->id]);

        // A product belonging to a different company must never validate,
        // even though the id itself is real — never a 500, never a
        // silent cross-tenant attach.
        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", array_merge(
            $this->validLeadPayload($ownProduct),
            ['product_id' => $foreignProduct->id],
        ))->assertUnprocessable()->assertJsonValidationErrors('product_id');

        // Negative / non-integer product_id.
        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", array_merge(
            $this->validLeadPayload($ownProduct),
            ['product_id' => -1],
        ))->assertUnprocessable();
        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", array_merge(
            $this->validLeadPayload($ownProduct),
            ['product_id' => 'DROP TABLE referrals;'],
        ))->assertUnprocessable();

        // Oversized name (max:255) — a very long payload must 422, not 500.
        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", array_merge(
            $this->validLeadPayload($ownProduct),
            ['name' => str_repeat('a', 5000)],
        ))->assertUnprocessable()->assertJsonValidationErrors('name');

        // consent must be strictly truthy ('accepted' rule) — a string
        // that merely LOOKS truthy doesn't satisfy it.
        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", array_merge(
            $this->validLeadPayload($ownProduct),
            ['consent' => 'maybe'],
        ))->assertUnprocessable()->assertJsonValidationErrors('consent');

        // No lead/client/referral was ever created by any of the above.
        $this->assertSame(0, Referral::where('agent_id', $agent->id)->count());
    }

    // TASK-034 QA gap-fill — the redirect route (GET /l/{token}, 60/min)
    // had no rate-limit test of its own; only the POST lead-capture
    // route (10/min) did.
    public function test_click_redirect_endpoint_is_rate_limited(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        for ($i = 0; $i < 60; $i++) {
            $this->get("/api/v1/l/{$link->token}");
        }

        $this->get("/api/v1/l/{$link->token}")->assertStatus(429);
    }

    public function test_token_is_not_the_numeric_database_id(): void
    {
        // Section 5 rule 5 / enumeration-resistance: a link's numeric id
        // must never work as a substitute for its token on either public
        // route.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $this->assertNotEquals((string) $link->id, $link->token);
        $this->assertGreaterThanOrEqual(64, strlen($link->token));

        $this->get("/api/v1/l/{$link->id}")->assertNotFound();
    }
}
