<?php

namespace Tests\Feature\Catalog;

use App\Enums\CommissionRateType;
use App\Models\Brand;
use App\Models\CatalogBrand;
use App\Models\CatalogCategory;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCatalogItem;
use App\Models\ProductCategory;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Commission\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-036 / TASK-211..213 — shared cross-company product catalog.
// Definition of Done: tenant isolation + security tests, cross-tenant
// access expected 403/404 (CLAUDE.md §5 rule 5, §9).
//
// Key contract under test, mirroring ADR-036's decision table exactly:
//   - catalog_brands / catalog_categories / product_catalog_items are
//     GLOBAL (no company_id) — any authenticated user can READ them,
//     only Super Admin can WRITE (create/update/delete), unlike Brand/
//     ProductCategory where Company Admin can also write their own.
//   - Linking a product to a catalog item is Super-Admin-only, even for
//     a Company Admin who owns the product being linked.
//   - Linking clears the product's local name/brand_id/category_id/
//     description/spec_description; price_satang and commission config
//     are completely untouched by link/unlink (ADR-036 §3's core point).
class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    // --- Read access: any authenticated user, any company -------------

    public function test_agent_can_list_catalog_brands(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CatalogBrand::factory()->create();

        $this->actingAs($agent)
            ->getJson('/api/v1/catalog-brands')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_agent_can_list_catalog_categories(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CatalogCategory::factory()->create();

        $this->actingAs($agent)
            ->getJson('/api/v1/catalog-categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_agent_can_view_a_product_catalog_item(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $item = ProductCatalogItem::factory()->create();

        $this->actingAs($agent)
            ->getJson("/api/v1/product-catalog-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $item->id);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/catalog-brands')->assertUnauthorized();
    }

    // --- Write access: Super Admin only, unlike Brand/ProductCategory --

    public function test_agent_cannot_create_a_catalog_brand(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/catalog-brands', ['name' => 'New Catalog Brand'])
            ->assertForbidden();
    }

    public function test_company_admin_cannot_create_a_catalog_brand(): void
    {
        // The key difference from BrandTest::test_company_admin_can_create_a_brand_in_their_own_company —
        // catalog_brands has no company_id for a Company Admin to own.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/catalog-brands', ['name' => 'New Catalog Brand'])
            ->assertForbidden();
    }

    public function test_company_admin_cannot_create_a_product_catalog_item(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $brand = CatalogBrand::factory()->create();
        $category = CatalogCategory::factory()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/product-catalog-items', [
                'catalog_brand_id' => $brand->id,
                'catalog_category_id' => $category->id,
                'name' => 'Shared Product',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_create_and_update_a_catalog_brand(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $created = $this->actingAs($superAdmin)
            ->postJson('/api/v1/catalog-brands', ['name' => 'Global Brand'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', null)
            ->json('data');

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/catalog-brands/{$created['id']}", ['name' => 'Renamed Global Brand'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Global Brand');
    }

    public function test_super_admin_can_create_a_product_catalog_item(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $brand = CatalogBrand::factory()->create();
        $category = CatalogCategory::factory()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/product-catalog-items', [
                'catalog_brand_id' => $brand->id,
                'catalog_category_id' => $category->id,
                'name' => 'Shared Product',
                'description' => 'Sold by multiple companies',
                // TASK-251 — required since the day saving this form also
                // creates a listing in every company. This test used to omit
                // it and was the first thing that failed when the rule landed,
                // which is the correct place for that change to show up.
                'default_price_satang' => 890000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Shared Product')
            ->assertJsonPath('data.catalog_brand.id', $brand->id);
    }

    public function test_a_catalog_item_cannot_be_created_without_a_default_price(): void
    {
        /*
         * TASK-251 / BR-7. Creating this item now creates a priced listing in
         * every company. Allowing the price to be omitted would mean choosing
         * one on the admin's behalf — and 0 บาท is not "blank", it is a
         * number a person reads as a decision.
         */
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/product-catalog-items', [
                'catalog_brand_id' => CatalogBrand::factory()->create()->id,
                'catalog_category_id' => CatalogCategory::factory()->create()->id,
                'name' => 'No Price',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_price_satang');
    }

    public function test_cannot_delete_a_catalog_brand_with_linked_catalog_items(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $brand = CatalogBrand::factory()->create();
        ProductCatalogItem::factory()->create(['catalog_brand_id' => $brand->id]);

        $this->actingAs($superAdmin)
            ->deleteJson("/api/v1/catalog-brands/{$brand->id}")
            ->assertUnprocessable();
    }

    // --- Link / unlink: the core ADR-036 §3 action ---------------------

    public function test_super_admin_can_link_a_product_to_a_catalog_item(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::factory()->for($company)->create([
            'name' => 'Standalone Name',
            'price_satang' => 990000,
        ]);
        $item = ProductCatalogItem::factory()->create(['name' => 'Shared Identity Name']);

        $response = $this->actingAs($superAdmin)
            ->postJson("/api/v1/products/{$product->id}/catalog-link", ['catalog_item_id' => $item->id])
            ->assertOk();

        $response->assertJsonPath('data.catalog_item_id', $item->id);
        // ADR-036 §3 — the RESOLVED name comes from the catalog item now,
        // and price_satang (a purely per-company field) is untouched.
        $response->assertJsonPath('data.name', 'Shared Identity Name');
        $response->assertJsonPath('data.price_satang', 990000);

        $product->refresh();
        $this->assertSame($item->id, $product->catalog_item_id);
        // Display text is cleared, not merely shadowed — see
        // ProductCatalogLinkService::link()'s docblock.
        $this->assertNull($product->name);
        // TASK-206 — brand_id/category_id now STAY populated: they are join
        // targets for commission/pipeline/filtering, not display text.
        $this->assertNotNull($product->brand_id);
        $this->assertNotNull($product->category_id);
        $this->assertSame(990000, $product->price_satang);
    }

    /**
     * TASK-206 (human decision, 2026-08-19) — linking keeps the product
     * pointing at its OWN company's brand/category row, mirrored by name from
     * the catalog item's global brand/category, created if absent.
     *
     * Without it three things break silently: category-scoped commission
     * rules stop matching (BR-2 wrong payout, written to an immutable ledger,
     * BR-4), ADR-026's category rung of the pipeline chain disappears, and
     * the product vanishes from every brand/category filter.
     */
    public function test_linking_mirrors_the_catalog_brand_and_category_into_the_products_own_company(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::factory()->for($company)->create();
        $item = ProductCatalogItem::factory()->create([
            'catalog_brand_id' => CatalogBrand::factory()->create(['name' => 'Shared Brand'])->id,
            'catalog_category_id' => CatalogCategory::factory()->create(['name' => 'Shared Category'])->id,
        ]);

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/products/{$product->id}/catalog-link", ['catalog_item_id' => $item->id])
            ->assertOk();

        $product->refresh();
        $brand = Brand::withoutGlobalScope(TenantScope::class)->find($product->brand_id);
        $category = ProductCategory::withoutGlobalScope(TenantScope::class)->find($product->category_id);

        $this->assertSame('Shared Brand', $brand->name);
        $this->assertSame('Shared Category', $category->name);
        // BR-6 — the mirrored rows belong to the PRODUCT's company, never a
        // global row and never the acting Super Admin's.
        $this->assertSame($company->id, $brand->company_id);
        $this->assertSame($company->id, $category->company_id);
    }

    /** Linking a second product must reuse the company's existing row, not duplicate it. */
    public function test_linking_reuses_an_existing_brand_of_the_same_name_instead_of_duplicating_it(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $existing = Brand::factory()->for($company)->create(['name' => 'Shared Brand']);
        $item = ProductCatalogItem::factory()->create([
            'catalog_brand_id' => CatalogBrand::factory()->create(['name' => 'Shared Brand'])->id,
        ]);

        foreach (Product::factory()->count(2)->for($company)->create() as $product) {
            $this->actingAs($superAdmin)
                ->postJson("/api/v1/products/{$product->id}/catalog-link", ['catalog_item_id' => $item->id])
                ->assertOk();

            $this->assertSame($existing->id, $product->refresh()->brand_id);
        }

        $this->assertSame(1, Brand::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $company->id)->where('name', 'Shared Brand')->count());
    }

    /**
     * The money regression this task exists to prevent: a category-scoped
     * commission rule (TASK-028) must still resolve for a linked product.
     */
    public function test_a_catalog_linked_product_still_matches_its_category_scoped_commission_rule(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::factory()->for($company)->create();
        $item = ProductCatalogItem::factory()->create([
            'catalog_category_id' => CatalogCategory::factory()->create(['name' => 'Health'])->id,
        ]);

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/products/{$product->id}/catalog-link", ['catalog_item_id' => $item->id])
            ->assertOk();

        $product->refresh();
        $rule = CommissionRule::create([
            'company_id' => $company->id,
            'cert_tier_id' => null,
            'product_id' => null,
            'product_category_id' => $product->category_id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 1000,
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        $resolved = app(CommissionService::class)->resolveCommissionRule($product);

        $this->assertNotNull($resolved, 'category-scoped rule no longer resolves for a linked product');
        $this->assertSame($rule->id, $resolved->id);
    }

    public function test_company_admin_cannot_link_their_own_product_to_a_catalog_item(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();
        $item = ProductCatalogItem::factory()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/catalog-link", ['catalog_item_id' => $item->id])
            ->assertForbidden();

        $product->refresh();
        $this->assertNull($product->catalog_item_id);
    }

    public function test_super_admin_cannot_link_a_product_belonging_to_a_deactivated_or_missing_catalog_item(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $product = Product::factory()->for($company)->create();

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/products/{$product->id}/catalog-link", ['catalog_item_id' => 999999])
            ->assertUnprocessable();
    }

    public function test_super_admin_can_unlink_a_product_back_to_standalone(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $item = ProductCatalogItem::factory()->create();
        $product = Product::factory()->for($company)->create(['catalog_item_id' => $item->id, 'name' => null, 'brand_id' => null, 'category_id' => null]);
        $newBrand = Brand::factory()->for($company)->create();
        $newCategory = ProductCategory::factory()->for($company)->create();

        $response = $this->actingAs($superAdmin)
            ->deleteJson("/api/v1/products/{$product->id}/catalog-link", [
                'name' => 'Restored Standalone Name',
                'brand_id' => $newBrand->id,
                'category_id' => $newCategory->id,
            ])
            ->assertOk();

        $response->assertJsonPath('data.catalog_item_id', null);
        $response->assertJsonPath('data.name', 'Restored Standalone Name');

        $product->refresh();
        $this->assertNull($product->catalog_item_id);
        $this->assertSame('Restored Standalone Name', $product->name);
        $this->assertSame($newBrand->id, $product->brand_id);
    }

    public function test_unlink_requires_a_full_local_identity(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $item = ProductCatalogItem::factory()->create();
        $product = Product::factory()->for($company)->create(['catalog_item_id' => $item->id, 'name' => null, 'brand_id' => null, 'category_id' => null]);

        $this->actingAs($superAdmin)
            ->deleteJson("/api/v1/products/{$product->id}/catalog-link", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'brand_id', 'category_id']);
    }

    public function test_company_admin_cannot_update_a_linked_products_price_or_commission(): void
    {
        // ADR-036 §5/§6 — ProductPolicy::update() denies Company Admin
        // outright on a catalog-linked product, not just identity fields:
        // once linked, price/commission become Super-Admin-only too
        // (human decision, ADR-036 decision table). Their own standalone
        // products are unaffected (see BrandTest-style CRUD tests
        // elsewhere for that baseline).
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $item = ProductCatalogItem::factory()->create();
        $product = Product::factory()->for($company)->create([
            'catalog_item_id' => $item->id,
            'name' => null,
            'brand_id' => null,
            'category_id' => null,
            'price_satang' => 500000,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$product->id}", ['price_satang' => 600000])
            ->assertForbidden();

        $product->refresh();
        $this->assertSame(500000, $product->price_satang);
    }

    public function test_super_admin_can_update_a_linked_products_price(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $item = ProductCatalogItem::factory()->create(['name' => 'Catalog Truth']);
        $product = Product::factory()->for($company)->create([
            'catalog_item_id' => $item->id,
            'name' => null,
            'brand_id' => null,
            'category_id' => null,
            'price_satang' => 500000,
        ]);

        // ProductService::update()'s defense-in-depth strip — see its
        // docblock. Even if a stale client form sends `name`, it must
        // never silently overwrite the catalog item's authority, even
        // when the actor IS allowed to write (Super Admin here).
        $this->actingAs($superAdmin)
            ->putJson("/api/v1/products/{$product->id}", [
                'name' => 'Attempted Local Override',
                'price_satang' => 600000,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Catalog Truth')
            ->assertJsonPath('data.price_satang', 600000);

        $product->refresh();
        $this->assertNull($product->name);
        $this->assertSame(600000, $product->price_satang);
    }

    /**
     * TASK-206 — the backfill sweep for products linked before the fix.
     */
    public function test_backfill_command_repairs_products_linked_before_the_fix(): void
    {
        $company = Company::factory()->create();
        $item = ProductCatalogItem::factory()->create([
            'catalog_brand_id' => CatalogBrand::factory()->create(['name' => 'Legacy Brand'])->id,
            'catalog_category_id' => CatalogCategory::factory()->create(['name' => 'Legacy Category'])->id,
        ]);

        // Exactly the state the OLD link() left behind.
        $stranded = Product::factory()->for($company)->create([
            'catalog_item_id' => $item->id,
            'name' => null,
            'brand_id' => null,
            'category_id' => null,
        ]);
        $healthy = Product::factory()->for($company)->create();

        $this->artisan('catalog:backfill-linked-taxonomy', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($stranded->fresh()->brand_id, 'dry run must not write');

        $this->artisan('catalog:backfill-linked-taxonomy')->assertSuccessful();

        $fixed = $stranded->fresh();
        $this->assertNotNull($fixed->brand_id);
        $this->assertNotNull($fixed->category_id);
        $this->assertSame('Legacy Brand', Brand::withoutGlobalScope(TenantScope::class)->find($fixed->brand_id)->name);
        $this->assertSame($company->id, Brand::withoutGlobalScope(TenantScope::class)->find($fixed->brand_id)->company_id);

        // A standalone product is not touched, and re-running is a no-op.
        $this->assertSame($healthy->brand_id, $healthy->fresh()->brand_id);
        $this->artisan('catalog:backfill-linked-taxonomy')->assertSuccessful();
        $this->assertSame($fixed->brand_id, $stranded->fresh()->brand_id);
    }
}
