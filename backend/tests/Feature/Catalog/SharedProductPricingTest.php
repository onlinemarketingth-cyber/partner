<?php

namespace Tests\Feature\Catalog;

use App\Enums\CommissionPlanType;
use App\Enums\PromotionStatus;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPricePromotion;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use App\Services\Catalog\ProductPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-254 / ADR-040 — what each company charges for the one shared product,
 * and whether it sells it at all.
 *
 * Two rules, deliberately NOT symmetrical, and the asymmetry is the whole
 * design:
 *
 *   PRICE FALLS BACK. A company that has set no price of its own pays the
 *   central one — the human's answer ("ถ้าไม่มีการแก้ไขให้ใช้ราคากลางไปก่อน").
 *   BR-7 is satisfied because the inherited number is one a Super Admin typed,
 *   not one this system invented.
 *
 *   PERMISSION TO SELL DOES NOT. It defaults to off and never inherits.
 *   Knowing what something would cost is not deciding to sell it, and the
 *   opposite default would put a product on sale in a company whose admin has
 *   never seen it.
 *
 * Everything money-shaped here is asserted at the point it becomes real — the
 * order amount and the commission base — because those are written once and
 * cannot be corrected afterwards (BR-4).
 */
class SharedProductPricingTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Company $aia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        $this->aia = Company::factory()->create(['name' => 'AIA']);
    }

    private function sharedProduct(int $centralPrice = 890000): Product
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'brand_id' => Brand::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Genesenn (กลาง)', 'is_active' => true])->id,
            'category_id' => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Anti Aging (กลาง)', 'is_active' => true, 'sort_order' => 0])->id,
            'name' => 'Vital Blueprint V5',
            'price_satang' => $centralPrice,
            'commission_plan_type' => CommissionPlanType::Unilevel,
            'is_active' => true,
        ]);
    }

    private function setting(Product $product, Company $company, ?int $price, bool $active = true): CompanyProductSetting
    {
        return CompanyProductSetting::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'price_satang' => $price,
            'is_active' => $active,
        ]);
    }

    private function pricing(): ProductPricingService
    {
        return app(ProductPricingService::class);
    }

    // ── Price: falls back, never invents ──────────────────────────────

    public function test_a_company_with_no_price_of_its_own_pays_the_central_one(): void
    {
        $product = $this->sharedProduct(890000);

        $this->assertSame(890000, $this->pricing()->effectivePriceSatang($product, $this->aia->id));
    }

    public function test_a_company_that_set_its_own_price_pays_that(): void
    {
        $product = $this->sharedProduct(890000);
        $this->setting($product, $this->aia, 790000);

        $this->assertSame(790000, $this->pricing()->effectivePriceSatang($product, $this->aia->id));
    }

    public function test_two_companies_charge_their_own_prices_for_the_same_row(): void
    {
        // The sentence the whole re-model exists for: one product, one row,
        // two prices, and neither company can see the other's.
        $product = $this->sharedProduct(890000);
        $this->setting($product, $this->thaiLife, 990000);
        $this->setting($product, $this->aia, 790000);

        $this->assertSame(990000, $this->pricing()->effectivePriceSatang($product, $this->thaiLife->id));
        $this->assertSame(790000, $this->pricing()->effectivePriceSatang($product, $this->aia->id));
    }

    public function test_a_deliberate_zero_is_a_price_not_an_absence(): void
    {
        /*
         * `?? central` and not `?: central`. A free onboarding item is a real
         * decision, and reading 0 as "unset" would silently charge the central
         * price for something a Super Admin marked free.
         */
        $product = $this->sharedProduct(890000);
        $this->setting($product, $this->aia, 0);

        $this->assertSame(0, $this->pricing()->effectivePriceSatang($product, $this->aia->id));
    }

    public function test_clearing_the_override_goes_back_to_the_central_price(): void
    {
        // null is an instruction, not a missing field: "stop overriding".
        $product = $this->sharedProduct(890000);
        $setting = $this->setting($product, $this->aia, 790000);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}/company-settings", [
                'company_id' => $this->aia->id,
                'price_satang' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.inherits_price', true);

        $this->assertNull($setting->refresh()->price_satang);
        $this->assertSame(890000, $this->pricing()->effectivePriceSatang($product, $this->aia->id));
    }

    public function test_a_companys_promotion_still_beats_its_own_price(): void
    {
        /*
         * The order of precedence, which TASK-136 fixed once already:
         * promotion → company price → central price. A promotion belongs to
         * ONE company, so the other company keeps paying its own price.
         */
        $product = $this->sharedProduct(890000);
        $this->setting($product, $this->aia, 790000);
        $this->setting($product, $this->thaiLife, 990000);

        ProductPricePromotion::withoutGlobalScopes()->create([
            'company_id' => $this->aia->id,
            'product_id' => $product->id,
            'discounted_price_satang' => 590000,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->assertSame(590000, $this->pricing()->effectivePriceSatang($product, $this->aia->id));
        $this->assertSame(990000, $this->pricing()->effectivePriceSatang($product, $this->thaiLife->id));
    }

    public function test_a_company_owned_product_ignores_all_of_this(): void
    {
        // The narrowing must be scoped exactly to shared rows: a normal
        // product's price is still its own column and nothing else.
        $product = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $this->aia->id,
            'brand_id' => Brand::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => $this->aia->id, 'name' => 'B', 'is_active' => true])->id,
            'category_id' => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => $this->aia->id, 'name' => 'C', 'is_active' => true, 'sort_order' => 0])->id,
            'name' => 'AIA Only',
            'price_satang' => 123400,
            'is_active' => true,
        ]);

        $this->assertSame(123400, $this->pricing()->effectivePriceSatang($product, $this->aia->id));
    }

    // ── Selling: never inherited ─────────────────────────────────────

    public function test_a_company_that_has_not_switched_it_on_cannot_sell_it(): void
    {
        $product = $this->sharedProduct();

        $this->assertFalse($product->isSellableBy($this->aia->id));
    }

    public function test_switching_it_on_is_per_company(): void
    {
        $product = $this->sharedProduct();
        $this->setting($product, $this->aia, null, active: true);

        $this->assertTrue($product->isSellableBy($this->aia->id));
        $this->assertFalse($product->isSellableBy($this->thaiLife->id));
    }

    public function test_the_platform_switching_it_off_stops_everybody(): void
    {
        // It is ONE product. A company cannot keep selling something the
        // platform withdrew, however its own switch is set.
        $product = $this->sharedProduct();
        $this->setting($product, $this->aia, null, active: true);

        $product->forceFill(['is_active' => false])->save();

        $this->assertFalse($product->fresh()->isSellableBy($this->aia->id));
    }

    // ── The write endpoint ───────────────────────────────────────────

    public function test_a_super_admin_sets_one_companys_price_and_switch(): void
    {
        $product = $this->sharedProduct();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}/company-settings", [
                'company_id' => $this->aia->id,
                'price_satang' => 790000,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.price_satang', 790000)
            ->assertJsonPath('data.is_active', true);

        $this->assertSame(790000, $this->pricing()->effectivePriceSatang($product, $this->aia->id));
    }

    public function test_a_company_admin_cannot_set_even_their_own_price(): void
    {
        // ADR-036's decision table and the human again on 2026-09-05: Super
        // Admin only. A Company Admin sees the result read-only.
        $product = $this->sharedProduct();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$product->id}/company-settings", [
                'company_id' => $this->aia->id,
                'price_satang' => 1,
            ])
            ->assertForbidden();
    }

    public function test_it_refuses_a_company_owned_product_rather_than_creating_a_second_price(): void
    {
        // Two places to set a price is how the two disagree.
        $product = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $this->aia->id,
            'brand_id' => Brand::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => $this->aia->id, 'name' => 'B', 'is_active' => true])->id,
            'category_id' => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => $this->aia->id, 'name' => 'C', 'is_active' => true, 'sort_order' => 0])->id,
            'name' => 'AIA Only',
            'price_satang' => 123400,
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}/company-settings", [
                'company_id' => $this->aia->id,
                'price_satang' => 1,
            ])
            ->assertUnprocessable();
    }

    public function test_a_price_change_is_recorded_with_both_numbers(): void
    {
        /*
         * Section 6. "The price is 7,900" answers a different question from
         * "it was 9,900 and this person changed it", and only the second one
         * is any use when an order does not match a quote.
         */
        $product = $this->sharedProduct(890000);
        $this->setting($product, $this->aia, 990000);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/products/{$product->id}/company-settings", [
                'company_id' => $this->aia->id,
                'price_satang' => 790000,
            ])
            ->assertOk();

        $row = AuditLog::where('action', 'product.company_setting_updated')->firstOrFail();

        $this->assertSame($this->aia->id, $row->company_id);
        $this->assertSame($superAdmin->id, $row->actor_user_id);
        $this->assertSame(990000, $row->old_values['price_satang']);
        $this->assertSame(790000, $row->new_values['price_satang']);
    }

    public function test_saving_the_same_values_again_writes_no_audit_row(): void
    {
        // A trail where every screen visit looks like a change is a trail
        // nobody reads.
        $product = $this->sharedProduct();
        $this->setting($product, $this->aia, 790000, active: true);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}/company-settings", [
                'company_id' => $this->aia->id,
                'price_satang' => 790000,
                'is_active' => true,
            ])
            ->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'product.company_setting_updated')->count());
    }

    // ── Where the money actually lands ───────────────────────────────

    public function test_the_resource_shows_the_viewers_price_beside_the_central_one(): void
    {
        /*
         * Both numbers, deliberately: a Super Admin editing the central price
         * has to see the central price, and any screen showing a customer
         * what they pay has to show the company's. Sending only one of them
         * forces every screen to guess which it got.
         */
        $product = $this->sharedProduct(890000);
        $this->setting($product, $this->aia, 790000, active: true);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.price_satang', 890000)
            ->assertJsonPath('data.effective_price_satang', 790000)
            ->assertJsonPath('data.is_shared', true)
            ->assertJsonPath('data.is_sellable_here', true);
    }
}
