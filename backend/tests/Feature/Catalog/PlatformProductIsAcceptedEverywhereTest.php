<?php

namespace Tests\Feature\Catalog;

use App\Models\AffiliateLink;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-09 — the other half of the same bug as PlatformProductContentTest,
 * found while fixing it.
 *
 * Thirteen Form Requests each wrote, by hand, the same rule:
 *
 *     Rule::exists('products', 'id')->where('company_id', $companyId)
 *
 * Correct while every product had exactly one owner. ADR-040 ended that, and
 * every one of those rules now refuses a platform product — so promoting a
 * company's product to the platform quietly breaks its affiliate links,
 * referrals, share links, storefront banners, recommendation pins, commission
 * rules and academy modules, all with "the selected product id is invalid"
 * about a product visible on the screen. Same shape as the brand/category
 * failure reported the day before: the list offers what the save refuses.
 *
 * ── THE LINE THIS FILE EXISTS TO PIN ──
 *
 * ADR-040's load-bearing sentence is that PERMISSION TO SELL NEVER INHERITS.
 * So "accept any platform product" is the WRONG fix wherever accepting one
 * would let a company put a product in front of a customer. Two rules:
 *
 *   CUSTOMER-FACING (affiliate link, referral, share link, storefront banner,
 *   recommendation pin, public lead form) — the company must actually have
 *   been granted the product in company_product_settings. Anything looser
 *   mints a public page for a product they were never allowed to sell.
 *
 *   INTERNAL CONFIGURATION (commission rules, override rules, academy
 *   modules) — any platform product, grant or no grant. Requiring the grant
 *   first creates an ordering trap (a company's rates could not be prepared
 *   before the product is switched on for them), and a rule attached to a
 *   product they cannot sell simply never fires. The row still carries their
 *   own company_id, so nothing crosses a tenant boundary either way.
 *
 * Another company's product stays refused by both. That is what the
 * hand-written rule was for, and none of this weakens it.
 */
class PlatformProductIsAcceptedEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private Company $aia;

    private Company $thaiLife;

    private Product $shared;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aia = Company::factory()->create(['name' => 'AIA']);
        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);

        $this->shared = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'name' => 'GENESENN 1-Year Vital Blueprint',
            'price_satang' => 2990000,
            'is_active' => true,
            'commission_plan_type' => 'unilevel',
        ]);

        $this->admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);
    }

    /** BR-1 — an agent may only mint links once Basic is passed. */
    private function certify(User $agent): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $agent->company_id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }

    /** Switch the shared product on for a company — never inherited. */
    private function grant(Company $company): void
    {
        CompanyProductSetting::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $company->id,
            'product_id' => $this->shared->id,
            'is_active' => true,
        ]);
    }

    // ── Customer-facing: the grant is the gate ───────────────────────

    public function test_a_granted_company_may_pin_the_shared_product(): void
    {
        // The pin puts the product on that company's own storefront.
        $this->grant($this->aia);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/product-recommendation-pins', ['product_id' => $this->shared->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->aia->id);
    }

    public function test_a_company_that_was_never_granted_the_product_may_not_pin_it(): void
    {
        /*
         * The rule this whole split exists for. Every company can SEE a
         * platform product; only the ones switched on may sell it. A pin is a
         * storefront promise, so it needs the grant, not just visibility.
         */
        $this->actingAs($this->admin)
            ->postJson('/api/v1/product-recommendation-pins', ['product_id' => $this->shared->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    public function test_a_revoked_grant_closes_the_door_again(): void
    {
        // is_active false is "not on sale here" — an existing row is not a
        // permanent yes.
        CompanyProductSetting::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $this->aia->id,
            'product_id' => $this->shared->id,
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/product-recommendation-pins', ['product_id' => $this->shared->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    public function test_another_companys_grant_does_not_open_it_for_me(): void
    {
        // The tenancy question, asked of the new subquery rather than the old
        // equality: it would be easy to write one that ignores whose grant it
        // reads.
        $this->grant($this->thaiLife);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/product-recommendation-pins', ['product_id' => $this->shared->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    public function test_an_agent_of_a_granted_company_may_mint_a_share_link(): void
    {
        // Customer-facing and agent-initiated — the case where a missing grant
        // would be visible to a member of the public.
        $this->grant($this->aia);
        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);
        $this->certify($agent);

        $this->actingAs($agent)
            ->postJson('/api/v1/product-shares', ['product_id' => $this->shared->id])
            ->assertCreated();
    }

    public function test_an_agent_of_an_ungranted_company_may_not(): void
    {
        // Certified, so the ONLY thing that can fail here is the product.
        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);
        $this->certify($agent);

        $this->actingAs($agent)
            ->postJson('/api/v1/product-shares', ['product_id' => $this->shared->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    public function test_a_referral_can_name_a_granted_shared_product(): void
    {
        $this->grant($this->aia);
        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);
        $this->certify($agent);

        $this->actingAs($agent)
            ->postJson('/api/v1/affiliate-links', ['product_id' => $this->shared->id])
            ->assertCreated();
    }

    // ── Internal configuration: visibility is enough ─────────────────

    public function test_a_company_may_set_its_own_commission_rate_on_a_shared_product(): void
    {
        /*
         * No grant. A company preparing its rates before the product is
         * switched on for it is a normal order of operations, and the rule it
         * writes is its own — it can never pay out on a sale that cannot
         * happen.
         */
        $tier = CertTier::factory()->create();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/commission-rules', [
                'product_id' => $this->shared->id,
                'cert_tier_id' => $tier->id,
                'rate_type' => 'percentage',
                'rate_value' => 500,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->aia->id);
    }

    // ── What must still be refused ───────────────────────────────────

    public function test_another_companys_product_is_still_refused(): void
    {
        // BR-6, and the reason the hand-written rule existed. Widening to "any
        // product" would have been the easy fix and is a tenancy leak.
        $theirs = Product::factory()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/product-recommendation-pins', ['product_id' => $theirs->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    public function test_a_deleted_shared_product_is_refused(): void
    {
        /*
         * `Rule::exists` reads the TABLE, not the model, so a soft-deleted
         * product satisfied the old rule. Same hole ValidatesProductTaxonomy
         * closed for brands yesterday; closing it here too rather than
         * carrying it forward into a new trait.
         */
        $this->grant($this->aia);
        $this->shared->delete();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/product-recommendation-pins', ['product_id' => $this->shared->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    public function test_a_public_lead_form_still_answers_for_its_own_link(): void
    {
        // Unauthenticated, and the company comes from the link rather than a
        // session — the one place where getting the scope wrong would be
        // invisible until a stranger filled in a form.
        $this->grant($this->aia);
        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);
        // BR-1 again: the capture Service returns null for an uncertified
        // agent, and the controller turns that into a deliberately vague 422.
        $this->certify($agent);

        $link = AffiliateLink::factory()->create([
            'company_id' => $this->aia->id,
            'agent_id' => $agent->id,
        ]);

        $this->postJson("/api/v1/public/affiliate-leads/{$link->token}", [
            'name' => 'สมชาย ทดสอบ',
            'phone' => '0812345678',
            'branch' => 'สีลม',
            'product_id' => $this->shared->id,
            'consent' => true,
        ])->assertSuccessful();
    }
}
