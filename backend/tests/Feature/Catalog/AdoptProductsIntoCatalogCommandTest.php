<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\CatalogBrand;
use App\Models\CatalogCategory;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCatalogItem;
use App\Models\ProductCategory;
use App\Models\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-251 — `php artisan catalog:adopt-products`.
 *
 * This command runs once, by hand, against a live database that already
 * holds the products a business is selling. That makes it the highest-risk
 * thing in the task and the reason its tests are about what it must NOT do:
 *
 *   • it must not change a single existing product's price, commission or
 *     active state — those are live sales configuration;
 *   • it must not merge two same-named products from different companies
 *     into one shared identity on nothing but a string match (ADR-036 §7);
 *   • --dry-run must write nothing at all;
 *   • a second run must be a no-op, because the first one may have been
 *     interrupted and the operator will run it again.
 */
class AdoptProductsIntoCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Company $genesenn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        $this->genesenn = Company::factory()->create(['name' => 'Genesenn']);
    }

    private function productIn(Company $company, string $name, int $priceSatang = 890000): Product
    {
        return Product::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $company->id,
            'brand_id' => Brand::withoutGlobalScope(TenantScope::class)->create([
                'company_id' => $company->id, 'name' => 'Genesenn', 'is_active' => true,
            ])->id,
            'category_id' => ProductCategory::withoutGlobalScope(TenantScope::class)->create([
                'company_id' => $company->id, 'name' => 'Anti Aging', 'is_active' => true, 'sort_order' => 0,
            ])->id,
            'name' => $name,
            'price_satang' => $priceSatang,
            'is_active' => true,
        ]);
    }

    private function listingsOf(ProductCatalogItem $item)
    {
        return Product::withoutGlobalScope(TenantScope::class)->where('catalog_item_id', $item->id)->get();
    }

    public function test_an_existing_product_becomes_a_catalog_item_at_its_own_price(): void
    {
        /*
         * BR-7 in the one place it could easily have been broken: the shared
         * default price is the price this product is ALREADY sold at — the
         * only number in this whole command that a human actually chose.
         */
        $this->productIn($this->thaiLife, 'Vital Blueprint V5', 890000);

        $this->artisan('catalog:adopt-products')->assertSuccessful();

        $item = ProductCatalogItem::firstOrFail();

        $this->assertSame('Vital Blueprint V5', $item->name);
        $this->assertSame(890000, (int) $item->default_price_satang);
        $this->assertSame('Genesenn', $item->catalogBrand->name);
        $this->assertSame('Anti Aging', $item->catalogCategory->name);
    }

    public function test_the_original_product_keeps_its_price_and_stays_on_sale(): void
    {
        // The live row. Adopting it must be an identity change and nothing
        // else — this company was selling this product a second ago.
        $product = $this->productIn($this->thaiLife, 'Vital Blueprint V5', 890000);

        $this->artisan('catalog:adopt-products')->assertSuccessful();

        $product->refresh();
        $this->assertSame(890000, (int) $product->price_satang);
        $this->assertTrue($product->is_active);
        $this->assertNotNull($product->catalog_item_id);
    }

    public function test_the_original_products_commission_rule_is_untouched(): void
    {
        /*
         * BR-2/BR-4. A commission rule that stopped matching after this
         * command would not throw anything — it would quietly pay the company
         * default rate into an immutable ledger, and be found weeks later in
         * somebody's payout.
         */
        $product = $this->productIn($this->thaiLife, 'Vital Blueprint V5');
        $rule = CommissionRule::factory()->create([
            'company_id' => $this->thaiLife->id,
            'product_id' => $product->id,
        ]);

        $this->artisan('catalog:adopt-products')->assertSuccessful();

        $rule->refresh();
        $this->assertSame($product->id, $rule->product_id);
        $this->assertSame(1, $product->commissionRules()->count());
    }

    public function test_every_other_company_gets_a_disabled_copy(): void
    {
        $this->productIn($this->thaiLife, 'Vital Blueprint V5', 890000);

        $this->artisan('catalog:adopt-products')->assertSuccessful();

        $item = ProductCatalogItem::firstOrFail();
        $copy = $this->listingsOf($item)->firstWhere('company_id', $this->genesenn->id);

        $this->assertNotNull($copy);
        $this->assertFalse($copy->is_active);
        $this->assertSame(890000, (int) $copy->price_satang);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->productIn($this->thaiLife, 'Vital Blueprint V5');

        $this->artisan('catalog:adopt-products --dry-run')->assertSuccessful();

        $this->assertSame(0, ProductCatalogItem::count());
        $this->assertSame(0, CatalogBrand::count());
        $this->assertSame(0, CatalogCategory::count());
        $this->assertSame(1, Product::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        // The operator's first run may have been interrupted; the second is
        // the normal way to find out whether it finished.
        $this->productIn($this->thaiLife, 'Vital Blueprint V5');

        $this->artisan('catalog:adopt-products')->assertSuccessful();
        $this->artisan('catalog:adopt-products')->assertSuccessful();

        $this->assertSame(1, ProductCatalogItem::count());
        $this->assertSame(2, Product::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_same_named_product_in_another_company_is_skipped_not_merged(): void
    {
        /*
         * ADR-036 §7, enforced rather than documented. Two products sharing a
         * name are not proof of one product; merging them would fuse two
         * independent price and commission histories on a guess, and there is
         * no undo for that. The skip is reported so a human can link them
         * deliberately if they really are the same thing.
         */
        $thaiLifeProduct = $this->productIn($this->thaiLife, 'Vital Blueprint V5', 890000);
        $genesennProduct = $this->productIn($this->genesenn, 'Vital Blueprint V5', 990000);

        $this->artisan('catalog:adopt-products')
            ->expectsOutputToContain('ข้าม')
            ->assertSuccessful();

        $this->assertSame(1, ProductCatalogItem::count());

        // The second company's product is left exactly as it was: standalone,
        // its own price, nothing lost.
        $genesennProduct->refresh();
        $thaiLifeProduct->refresh();
        $this->assertNotNull($thaiLifeProduct->catalog_item_id);
        $this->assertNull($genesennProduct->catalog_item_id);
        $this->assertSame(990000, (int) $genesennProduct->price_satang);
    }

    public function test_a_product_already_in_the_catalog_is_left_alone(): void
    {
        $item = ProductCatalogItem::factory()->create(['default_price_satang' => 500000]);
        $linked = $this->productIn($this->thaiLife, 'Already Linked');
        $linked->forceFill(['catalog_item_id' => $item->id])->save();

        $this->artisan('catalog:adopt-products')->assertSuccessful();

        $this->assertSame(1, ProductCatalogItem::count());
        $this->assertSame($item->id, $linked->refresh()->catalog_item_id);
    }

    public function test_a_soft_deleted_product_is_not_resurrected_into_the_catalog(): void
    {
        // Somebody removed it. Adopting it would put it back in front of
        // every company at once.
        $this->productIn($this->thaiLife, 'Discontinued')->delete();

        $this->artisan('catalog:adopt-products')->assertSuccessful();

        $this->assertSame(0, ProductCatalogItem::count());
    }

    public function test_one_company_can_be_adopted_on_its_own(): void
    {
        // --company exists so a cautious operator can do this one tenant at a
        // time on a live system, instead of all of it in one irreversible go.
        $this->productIn($this->thaiLife, 'Thai Life Only');
        $this->productIn($this->genesenn, 'Genesenn Only');

        $this->artisan("catalog:adopt-products --company={$this->thaiLife->id}")->assertSuccessful();

        $this->assertSame(1, ProductCatalogItem::count());
        $this->assertSame('Thai Life Only', ProductCatalogItem::firstOrFail()->name);
    }
}
