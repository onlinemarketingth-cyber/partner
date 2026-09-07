<?php

namespace Tests\Feature\Catalog;

use App\Models\AffiliateLink;
use App\Models\Brand;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductRecommendationPin;
use App\Models\Referral;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-257 / ADR-040 — every OTHER place a product's price reaches a screen.
 *
 * ADR-040 §4 named this risk in advance: "There is one choke point today; there
 * must still be exactly one afterwards, and every caller must pass the company
 * rather than defaulting to the acting user's."
 *
 * A sweep found four resources that had not been given one. None of them was
 * wrong before — a company-owned product IS its own company, so reading
 * `price_satang` off the row was correct for every product that exists today,
 * and stays correct. Each becomes wrong the moment a product is promoted,
 * which is to say all four break together, in production, on the day the
 * command runs.
 *
 * The affiliate landing page had a second defect that no price test would have
 * caught: its product list filtered on `company_id`, so it excluded every
 * shared product outright. A prospect would simply have seen a shorter
 * catalogue than the agent who sent them the link.
 */
class SharedProductPriceEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private User $agent;

    private Product $shared;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        $this->agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $this->shared = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'brand_id' => Brand::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Genesenn (กลาง)', 'is_active' => true])->id,
            'category_id' => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Anti Aging (กลาง)', 'is_active' => true, 'sort_order' => 0])->id,
            'name' => 'Vital Blueprint V5',
            'price_satang' => 890000,
            'is_active' => true,
        ]);

        // Thai Life sells it, at its own lower price. Any answer of 890000
        // below is the central price leaking through.
        CompanyProductSetting::withoutGlobalScopes()->create([
            'company_id' => $this->thaiLife->id,
            'product_id' => $this->shared->id,
            'price_satang' => 790000,
            'is_active' => true,
        ]);
    }

    public function test_a_deal_card_shows_the_price_the_order_will_charge(): void
    {
        /*
         * ReferralResource. The agent looking at this row is the one who has
         * to explain the number to the customer, and
         * OrderService::createForReferral() prices the order for this same
         * company — so a central price here would put two different amounts
         * in front of the same person.
         */
        $referral = Referral::factory()->create([
            'company_id' => $this->thaiLife->id,
            'agent_id' => $this->agent->id,
            'product_id' => $this->shared->id,
        ]);

        $this->actingAs($this->agent)
            ->getJson("/api/v1/referrals/{$referral->id}")
            ->assertOk()
            ->assertJsonPath('data.product.price_satang', 790000);
    }

    public function test_a_storefront_pin_shows_this_companys_price(): void
    {
        // ProductRecommendationPinResource — a pin belongs to a company, and
        // so does the price it advertises on that company's storefront.
        ProductRecommendationPin::withoutGlobalScopes()->create([
            'company_id' => $this->thaiLife->id,
            'product_id' => $this->shared->id,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->agent)
            ->getJson('/api/v1/product-recommendation-pins')
            ->assertOk()
            ->assertJsonPath('data.0.product.price_satang', 790000);
    }

    // ── The public affiliate landing page ────────────────────────────

    private function affiliateToken(?int $productId): string
    {
        return AffiliateLink::factory()->create([
            'company_id' => $this->thaiLife->id,
            'agent_id' => $this->agent->id,
            'product_id' => $productId,
        ])->token;
    }

    public function test_an_affiliate_link_to_one_product_prices_it_for_that_company(): void
    {
        $this->getJson('/api/v1/public/affiliate-leads/'.$this->affiliateToken($this->shared->id))
            ->assertOk()
            ->assertJsonPath('data.product.price_satang', 790000);
    }

    public function test_a_prospect_choosing_from_the_catalogue_can_see_the_shared_product_at_all(): void
    {
        /*
         * The defect no price assertion would have found. The list filtered on
         * `company_id`, which a platform product does not have, so it was
         * simply absent — the prospect saw a shorter catalogue than the agent
         * who sent them the link, with nothing on the page to say why.
         */
        $payload = $this->getJson('/api/v1/public/affiliate-leads/'.$this->affiliateToken(null))
            ->assertOk()
            ->json('data.products');

        $this->assertCount(1, $payload);
        $this->assertSame($this->shared->id, $payload[0]['id']);
        $this->assertSame(790000, $payload[0]['price_satang']);
    }

    public function test_a_product_the_company_has_not_switched_on_stays_off_the_list(): void
    {
        /*
         * Permission to sell never inherits (ADR-040 §2). Widening the query
         * to include platform rows must not turn into "every company sells
         * everything the moment it exists" — which is the failure this whole
         * design was arranged to avoid.
         */
        CompanyProductSetting::withoutGlobalScopes()
            ->where('company_id', $this->thaiLife->id)
            ->where('product_id', $this->shared->id)
            ->update(['is_active' => false]);

        $this->getJson('/api/v1/public/affiliate-leads/'.$this->affiliateToken(null))
            ->assertOk()
            ->assertJsonCount(0, 'data.products');
    }

    public function test_a_companys_own_product_is_still_listed_and_still_priced_from_its_row(): void
    {
        // The regression guard: every product that exists today takes this
        // branch, and nothing about it changed.
        $own = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $this->thaiLife->id,
            'brand_id' => $this->shared->brand_id,
            'category_id' => $this->shared->category_id,
            'name' => 'Thai Life Only',
            'price_satang' => 123400,
            'is_active' => true,
        ]);

        $products = collect($this->getJson('/api/v1/public/affiliate-leads/'.$this->affiliateToken(null))
            ->assertOk()
            ->json('data.products'));

        $this->assertSame(123400, $products->firstWhere('id', $own->id)['price_satang']);
    }
}
