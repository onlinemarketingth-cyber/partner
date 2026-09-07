<?php

namespace Tests\Feature\Catalog;

use App\Enums\PromotionStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPricePromotion;
use App\Models\ProductShareLink;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-257 / ADR-040 — the price a CUSTOMER is shown for a shared product.
 *
 * TASK-136 exists because two parts of this system once answered "what does
 * this cost" differently: the share page advertised a promotional price and
 * the checkout charged the list price. That was called what it is — a consumer
 * complaint, not a bug report — and collapsed into one service.
 *
 * ADR-040 re-opened exactly that gap without anybody touching those two files.
 * A platform product has no company of its own, so
 * `effectivePriceSatang($product)` with no company fell back to the CENTRAL
 * price, while OrderService::createForReferral() had already been given the
 * referral's company and charged that company's. The page and the order would
 * have disagreed about money again — silently, and only for shared products,
 * which is to say only after the promotion command runs on production.
 *
 * So these tests are all one assertion in different clothes: the number on the
 * page is the number on the order.
 */
class SharedProductPublicPriceTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);

        $this->product = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'brand_id' => Brand::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Genesenn (กลาง)', 'is_active' => true])->id,
            'category_id' => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Anti Aging (กลาง)', 'is_active' => true, 'sort_order' => 0])->id,
            'name' => 'Vital Blueprint V5',
            // The CENTRAL price — deliberately different from what Thai Life
            // charges below, so a fallback to it is visible rather than
            // coincidentally right.
            'price_satang' => 890000,
            'is_active' => true,
        ]);
    }

    private function setting(?int $price, bool $active = true): void
    {
        CompanyProductSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $this->thaiLife->id, 'product_id' => $this->product->id],
            ['price_satang' => $price, 'is_active' => $active],
        );
    }

    /** The share link an agent of Thai Life sends to a customer. */
    private function shareToken(): string
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $link = ProductShareLink::factory()->create([
            'company_id' => $this->thaiLife->id,
            'agent_id' => $agent->id,
            'product_id' => $this->product->id,
        ]);

        return $link->token;
    }

    private function page(): array
    {
        return $this->getJson('/api/v1/public/product-shares/'.$this->shareToken())
            ->assertOk()
            ->json('data.product');
    }

    public function test_the_page_shows_what_this_company_charges(): void
    {
        // Thai Life priced it lower than the centre. Without the company being
        // passed, the page advertised 8,900 and the checkout took 7,900.
        $this->setting(790000);

        $product = $this->page();

        $this->assertSame(790000, $product['payable_price_satang']);
        $this->assertSame(790000, $product['price_satang']);
    }

    public function test_a_company_with_no_price_of_its_own_shows_the_central_one(): void
    {
        // The fallback is the human's decision ("ถ้าไม่มีการแก้ไขให้ใช้ราคา
        // กลางไปก่อน"), and it must still apply — this is not a case of the
        // company context being missing, it is the context answering.
        $this->setting(null);

        $this->assertSame(890000, $this->page()['payable_price_satang']);
    }

    public function test_a_deliberate_zero_is_charged_as_zero(): void
    {
        // `?? central` and not `?: central`, asserted where a customer sees it.
        $this->setting(0);

        $this->assertSame(0, $this->page()['payable_price_satang']);
    }

    public function test_the_struck_through_price_is_this_companys_own(): void
    {
        /*
         * The subtler half. A promotion discounts from the company's list
         * price; showing the CENTRAL price struck through would advertise a
         * discount the company never offered — bigger than the real one, and
         * against a number nobody ever charged.
         */
        $this->setting(790000);

        ProductPricePromotion::create([
            'company_id' => $this->thaiLife->id,
            'product_id' => $this->product->id,
            'discounted_price_satang' => 690000,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $product = $this->page();

        $this->assertSame(690000, $product['payable_price_satang']);
        $this->assertSame(690000, $product['promotional_price_satang']);
        // Struck through against 7,900 — the company's own list price — not
        // against the platform's 8,900.
        $this->assertSame(790000, $product['price_satang']);
    }

    public function test_a_promotion_is_found_at_all_for_a_shared_product(): void
    {
        /*
         * A promotion belongs to a COMPANY. Resolved from the product alone it
         * would look for one with a NULL company and find nothing — so a
         * shared product could never be discounted, and the failure would look
         * like "the promotion feature is broken" rather than like a missing
         * argument.
         */
        $this->setting(890000);

        ProductPricePromotion::create([
            'company_id' => $this->thaiLife->id,
            'product_id' => $this->product->id,
            'discounted_price_satang' => 690000,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $this->assertNotNull($this->page()['promotional_price_satang']);
    }

    public function test_a_company_owned_product_is_completely_unaffected(): void
    {
        // The regression guard for every product that exists today.
        $own = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $this->thaiLife->id,
            'brand_id' => $this->product->brand_id,
            'category_id' => $this->product->category_id,
            'name' => 'Thai Life Only',
            'price_satang' => 123400,
            'is_active' => true,
        ]);

        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $this->thaiLife->id,
            'agent_id' => $agent->id,
            'product_id' => $own->id,
        ]);

        $this->getJson('/api/v1/public/product-shares/'.$link->token)
            ->assertOk()
            ->assertJsonPath('data.product.price_satang', 123400)
            ->assertJsonPath('data.product.payable_price_satang', 123400);
    }
}
