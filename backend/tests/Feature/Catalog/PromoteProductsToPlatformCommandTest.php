<?php

namespace Tests\Feature\Catalog;

use App\Enums\CommissionPlanType;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Referral;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\Scopes\TenantScope;
use App\Services\Catalog\ProductPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-255 / ADR-040 §5 — the command that runs once, by hand, against the
 * products a business is actually selling.
 *
 * It is the highest-risk thing in this whole re-model, so the tests are about
 * what must NOT move:
 *
 *   • the product's ID. Fifteen tables point at it, two of them immutable
 *     money records (BR-4). Promotion is an ownership change on the row that
 *     is already there — anything that created a new row would orphan every
 *     one of them.
 *   • the original company's experience. Same price, same on-sale state, same
 *     commission rules. The day after promotion should be indistinguishable
 *     from the day before, for them.
 *   • a category-scoped commission rule. It matches through the category, the
 *     category has to become the platform's, and the rule would then stop
 *     matching SILENTLY — a wrong payout in an immutable ledger. The command
 *     refuses rather than deciding that for somebody.
 */
class PromoteProductsToPlatformCommandTest extends TestCase
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

    private function product(Company $company, string $name = 'Vital Blueprint V5', int $price = 890000, bool $active = true): Product
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $company->id,
            'brand_id' => Brand::withoutGlobalScope(SharedOrTenantScope::class)->create([
                'company_id' => $company->id, 'name' => 'Genesenn', 'is_active' => true,
            ])->id,
            'category_id' => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)->create([
                'company_id' => $company->id, 'name' => 'Anti Aging', 'is_active' => true, 'sort_order' => 0,
            ])->id,
            'name' => $name,
            'price_satang' => $price,
            'is_active' => $active,
        ]);
    }

    private function settingFor(Company $company, Product $product): ?CompanyProductSetting
    {
        return CompanyProductSetting::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->first();
    }

    // ── The row itself ───────────────────────────────────────────────

    public function test_the_product_keeps_its_id_and_becomes_platform_owned(): void
    {
        $product = $this->product($this->thaiLife);
        $idBefore = $product->id;

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $product->refresh();
        $this->assertSame($idBefore, $product->id);
        $this->assertNull($product->company_id);
        $this->assertTrue($product->isShared());
        $this->assertSame(1, Product::withoutGlobalScope(SharedOrTenantScope::class)->count());
    }

    public function test_a_referral_and_an_order_against_it_still_point_at_the_same_row(): void
    {
        /*
         * The reason the id may not change, stated as a test rather than as a
         * comment. These rows cannot be rewritten (BR-4), so a promotion that
         * moved the product would leave them describing a product that no
         * longer exists.
         */
        $product = $this->product($this->thaiLife);
        $referral = Referral::factory()->create([
            'company_id' => $this->thaiLife->id,
            'product_id' => $product->id,
        ]);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $this->assertSame($product->id, $referral->refresh()->product_id);
        $this->assertSame(0, Order::withoutGlobalScope(TenantScope::class)->whereNull('product_id')->count());
    }

    public function test_a_product_scoped_commission_rule_is_untouched(): void
    {
        // It matches on product_id, which does not move — so this keeps
        // working with no migration at all. That is the whole reason the
        // design links outward from the product row.
        $product = $this->product($this->thaiLife);
        $rule = CommissionRule::factory()->create([
            'company_id' => $this->thaiLife->id,
            'product_id' => $product->id,
        ]);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $this->assertSame($product->id, $rule->refresh()->product_id);
    }

    // ── The original company notices nothing ─────────────────────────

    public function test_the_original_company_keeps_the_same_price_without_an_override(): void
    {
        $product = $this->product($this->thaiLife, price: 890000);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $setting = $this->settingFor($this->thaiLife, $product);

        $this->assertNotNull($setting);
        // No override — it inherits, and what it inherits is its own old price.
        $this->assertNull($setting->price_satang);
        $this->assertSame(890000, app(ProductPricingService::class)
            ->effectivePriceSatang($product->refresh(), $this->thaiLife->id));
    }

    public function test_the_original_company_keeps_selling_it(): void
    {
        $product = $this->product($this->thaiLife, active: true);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $this->assertTrue($this->settingFor($this->thaiLife, $product)->is_active);
        $this->assertTrue($product->refresh()->isSellableBy($this->thaiLife->id));
    }

    public function test_a_product_they_had_switched_off_stays_off(): void
    {
        // Promotion is not an opportunity to quietly re-list something the
        // company withdrew.
        $product = $this->product($this->thaiLife, active: false);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $this->assertFalse($this->settingFor($this->thaiLife, $product)->is_active);
    }

    public function test_the_plan_type_it_was_inheriting_is_written_down(): void
    {
        /*
         * It inherited from its company; in a moment there is no company to
         * inherit from, and effectivePlanType() throws rather than guess.
         * Copying the company's current value invents nothing — it writes
         * down what was already true.
         */
        $this->thaiLife->forceFill(['commission_plan_type' => CommissionPlanType::Binary])->save();
        $product = $this->product($this->thaiLife);
        $this->assertNull($product->commission_plan_type);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $this->assertSame(CommissionPlanType::Binary, $product->refresh()->commission_plan_type);
    }

    // ── Every other company ──────────────────────────────────────────

    public function test_every_other_company_gets_the_right_to_sell_it_switched_off(): void
    {
        $product = $this->product($this->thaiLife);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $setting = $this->settingFor($this->aia, $product);

        $this->assertNotNull($setting);
        $this->assertFalse($setting->is_active);
        $this->assertNull($setting->price_satang);
        $this->assertFalse($product->refresh()->isSellableBy($this->aia->id));
    }

    public function test_and_they_inherit_the_central_price_the_moment_they_switch_it_on(): void
    {
        $product = $this->product($this->thaiLife, price: 890000);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $this->settingFor($this->aia, $product)->forceFill(['is_active' => true])->save();

        $this->assertTrue($product->refresh()->isSellableBy($this->aia->id));
        $this->assertSame(890000, app(ProductPricingService::class)
            ->effectivePriceSatang($product, $this->aia->id));
    }

    // ── The taxonomy ─────────────────────────────────────────────────

    public function test_it_points_at_a_platform_brand_and_category(): void
    {
        // A shared product cannot belong to one company's taxonomy: the other
        // companies cannot even see those rows.
        $product = $this->product($this->thaiLife);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $product->refresh();
        $this->assertNull(Brand::withoutGlobalScope(SharedOrTenantScope::class)->find($product->brand_id)->company_id);
        $this->assertNull(ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)->find($product->category_id)->company_id);
    }

    public function test_the_companys_own_brand_row_is_left_alone(): void
    {
        // Other products of that company still point at it. Moving it would
        // take them with it.
        $product = $this->product($this->thaiLife);
        $brandBefore = $product->brand_id;

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $original = Brand::withoutGlobalScope(SharedOrTenantScope::class)->find($brandBefore);
        $this->assertNotNull($original);
        $this->assertSame($this->thaiLife->id, $original->company_id);
    }

    public function test_two_products_sharing_a_brand_name_share_one_platform_brand(): void
    {
        $this->product($this->thaiLife, 'V5');
        $this->product($this->thaiLife, 'V8');

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $this->assertSame(1, Brand::withoutGlobalScope(SharedOrTenantScope::class)->whereNull('company_id')->count());
    }

    // ── The refusal ──────────────────────────────────────────────────

    public function test_a_category_scoped_commission_rule_stops_the_promotion(): void
    {
        /*
         * The one that would cost money silently. The rule matches through
         * `product_category_id`; the category has to become the platform's,
         * and then the rule matches nothing — the sale quietly falls to the
         * company default rate and the wrong number lands in a ledger row
         * nobody can rewrite.
         *
         * A migration must not make that call at 3am. It names the rule and
         * stops.
         */
        $product = $this->product($this->thaiLife);
        CommissionRule::factory()->create([
            'company_id' => $this->thaiLife->id,
            'product_id' => null,
            'product_category_id' => $product->category_id,
        ]);

        $this->artisan('catalog:promote-products')
            ->expectsOutputToContain('ข้าม')
            ->assertSuccessful();

        $this->assertNotNull($product->refresh()->company_id);
        $this->assertSame(0, CompanyProductSetting::withoutGlobalScopes()->count());
    }

    // ── Operator safety ──────────────────────────────────────────────

    public function test_dry_run_writes_nothing(): void
    {
        $product = $this->product($this->thaiLife);

        $this->artisan('catalog:promote-products --dry-run')->assertSuccessful();

        $this->assertSame($this->thaiLife->id, $product->refresh()->company_id);
        $this->assertSame(0, CompanyProductSetting::withoutGlobalScopes()->count());
    }

    public function test_running_it_twice_promotes_nothing_the_second_time(): void
    {
        // The first run may have been interrupted; running it again is how an
        // operator finds out whether it finished.
        $this->product($this->thaiLife);

        $this->artisan('catalog:promote-products')->assertSuccessful();
        $this->artisan('catalog:promote-products')->assertSuccessful();

        $this->assertSame(2, CompanyProductSetting::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::where('action', 'product.promoted_to_platform')->count());
    }

    public function test_one_product_can_be_promoted_on_its_own(): void
    {
        // --product exists so a cautious operator can do this one row at a
        // time on a live system and check the result in between.
        $keep = $this->product($this->thaiLife, 'Stays Local');
        $move = $this->product($this->thaiLife, 'Goes Platform');

        $this->artisan("catalog:promote-products --product={$move->id}")->assertSuccessful();

        $this->assertNull($move->refresh()->company_id);
        $this->assertSame($this->thaiLife->id, $keep->refresh()->company_id);
    }

    public function test_the_promotion_is_recorded_with_what_it_moved(): void
    {
        $product = $this->product($this->thaiLife);

        $this->artisan('catalog:promote-products')->assertSuccessful();

        $row = AuditLog::where('action', 'product.promoted_to_platform')->firstOrFail();

        $this->assertSame($this->thaiLife->id, $row->company_id);
        $this->assertSame($this->thaiLife->id, $row->old_values['company_id']);
        $this->assertNull($row->new_values['company_id']);
        $this->assertSame(2, $row->new_values['companies_granted']);
    }
}
