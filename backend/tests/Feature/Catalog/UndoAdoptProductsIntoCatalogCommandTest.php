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
use App\Models\Referral;
use App\Models\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-252 — the way back from TASK-251.
 *
 * The human rejected the copy-per-company model after seeing it on
 * production, and the structural replacement must not be built on top of a
 * database still carrying the copies. So this command reverses one specific
 * command, and the tests are almost entirely about the cases where it must
 * REFUSE:
 *
 *   • a copy somebody has already used is not deleted (it is data now);
 *   • the original product's id, price, commission rules and history are
 *     never touched — only the identity columns adoption moved are moved
 *     back;
 *   • --dry-run writes nothing;
 *   • a catalog item nobody adopted (created by hand) is left alone.
 */
class UndoAdoptProductsIntoCatalogCommandTest extends TestCase
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

    /** The exact production shape: one real product, then adoption. */
    private function adoptedProduct(string $name = 'Vital Blueprint V5', int $price = 890000): Product
    {
        $product = Product::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $this->thaiLife->id,
            'brand_id' => Brand::withoutGlobalScope(TenantScope::class)->create([
                'company_id' => $this->thaiLife->id, 'name' => 'Genesenn', 'is_active' => true,
            ])->id,
            'category_id' => ProductCategory::withoutGlobalScope(TenantScope::class)->create([
                'company_id' => $this->thaiLife->id, 'name' => 'Anti Aging', 'is_active' => true, 'sort_order' => 0,
            ])->id,
            'name' => $name,
            'description' => 'ของเดิม',
            'price_satang' => $price,
            'is_active' => true,
        ]);

        $this->artisan('catalog:adopt-products')->assertSuccessful();

        return $product->refresh();
    }

    private function allProducts()
    {
        return Product::withoutGlobalScope(TenantScope::class)->withTrashed()->get();
    }

    public function test_the_copies_are_gone_and_the_original_stands_alone_again(): void
    {
        $original = $this->adoptedProduct();
        $this->assertCount(2, $this->allProducts());

        $this->artisan('catalog:undo-adopt-products')->assertSuccessful();

        $this->assertCount(1, $this->allProducts());
        $original->refresh();
        $this->assertNull($original->catalog_item_id);
        $this->assertSame(0, ProductCatalogItem::withTrashed()->count());
    }

    public function test_the_original_gets_its_name_back(): void
    {
        // Adoption cleared name/description onto the catalog item. Leaving
        // them null after the item is deleted would leave a product with no
        // name at all — the worst possible outcome of a rollback.
        $original = $this->adoptedProduct('Vital Blueprint V5');

        $this->artisan('catalog:undo-adopt-products')->assertSuccessful();

        $original->refresh();
        $this->assertSame('Vital Blueprint V5', $original->name);
        $this->assertSame('ของเดิม', $original->description);
        $this->assertNotNull($original->brand_id);
        $this->assertNotNull($original->category_id);
    }

    public function test_the_original_keeps_its_id_price_and_commission_rule(): void
    {
        /*
         * The point of the whole design: adoption was an identity change, so
         * the reversal is too. A rollback that recreated the row under a new
         * id would orphan every referral, order and ledger row pointing at it
         * (BR-4 — those cannot be rewritten).
         */
        $original = $this->adoptedProduct('Vital Blueprint V5', 890000);
        $rule = CommissionRule::factory()->create([
            'company_id' => $this->thaiLife->id,
            'product_id' => $original->id,
        ]);
        $idBefore = $original->id;

        $this->artisan('catalog:undo-adopt-products')->assertSuccessful();

        $original->refresh();
        $this->assertSame($idBefore, $original->id);
        $this->assertSame(890000, (int) $original->price_satang);
        $this->assertTrue($original->is_active);
        $this->assertSame($original->id, $rule->refresh()->product_id);
    }

    public function test_a_copy_that_somebody_already_used_stops_the_whole_item(): void
    {
        /*
         * The refusal that matters. A copy with a referral against it is not
         * a copy any more, and deleting it would take a sale with it. When
         * that is found, NOTHING about this item is changed — not even the
         * original — because a half-reversed item is harder to reason about
         * than an un-reversed one.
         */
        $original = $this->adoptedProduct();
        $copy = Product::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $this->aia->id)
            ->firstOrFail();

        Referral::factory()->create([
            'company_id' => $this->aia->id,
            'product_id' => $copy->id,
        ]);

        $this->artisan('catalog:undo-adopt-products')
            ->expectsOutputToContain('ข้าม')
            ->assertSuccessful();

        $this->assertCount(2, $this->allProducts());
        $this->assertNotNull($original->refresh()->catalog_item_id);
        $this->assertSame(1, ProductCatalogItem::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->adoptedProduct();

        $this->artisan('catalog:undo-adopt-products --dry-run')->assertSuccessful();

        $this->assertCount(2, $this->allProducts());
        $this->assertSame(1, ProductCatalogItem::count());
    }

    public function test_a_catalog_item_nobody_adopted_is_left_alone(): void
    {
        // Created by hand through the admin screen, linked to nothing. This
        // command reverses one specific command; it is not a catalog cleaner.
        ProductCatalogItem::factory()->create(['default_price_satang' => 500000]);

        $this->artisan('catalog:undo-adopt-products')
            ->expectsOutputToContain('ข้าม')
            ->assertSuccessful();

        $this->assertSame(1, ProductCatalogItem::count());
    }

    public function test_the_shared_brand_and_category_it_created_go_too(): void
    {
        // Adoption mirrored them into the global tables; leaving them behind
        // would make "ย้อนกลับแล้ว" untrue on the catalog screen.
        $this->adoptedProduct();
        $this->assertSame(1, CatalogBrand::count());

        $this->artisan('catalog:undo-adopt-products')->assertSuccessful();

        $this->assertSame(0, CatalogBrand::withTrashed()->count());
        $this->assertSame(0, CatalogCategory::withTrashed()->count());
    }

    public function test_a_shared_brand_still_used_by_another_item_survives(): void
    {
        // Only the rows this reversal orphaned are removed.
        $this->adoptedProduct();
        $brand = CatalogBrand::firstOrFail();
        ProductCatalogItem::factory()->create([
            'catalog_brand_id' => $brand->id,
            'default_price_satang' => 500000,
        ]);

        $this->artisan('catalog:undo-adopt-products')->assertSuccessful();

        $this->assertSame(1, CatalogBrand::count());
    }

    public function test_running_it_twice_is_harmless(): void
    {
        $this->adoptedProduct();

        $this->artisan('catalog:undo-adopt-products')->assertSuccessful();
        $this->artisan('catalog:undo-adopt-products')->assertSuccessful();

        $this->assertCount(1, $this->allProducts());
    }
}
