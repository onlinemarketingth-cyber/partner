<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\CertTier;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\SharedOrTenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-09 (human: "เวลาเพิ่มสินค้า หมวดหมู่ แบรนด์ขึ้นซ้อนกัน ต้องมีตัวเดียว
 * … เรื่องสินค้ากลางคุณทำให้จบ").
 *
 * They were not duplicates in the data sense — one was the company's own brand
 * and one was the platform's, both named "De La Lita" — but on a product form
 * they are two identical words and picking between them is a coin flip.
 *
 * They appeared because `catalog:promote-products` deliberately leaves the
 * company's brand row alone: at the moment it promotes ONE product it cannot
 * know whether the company's others still use that brand, and deleting a brand
 * out from under a product is far worse than a duplicate in a dropdown.
 * Promote every product a company has, and the row is simply left behind with
 * nothing pointing at it.
 *
 * So this command is the second half, and nearly all of it is about what it
 * REFUSES to remove.
 */
class TidyPromotedTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);
    }

    private function brand(?int $companyId, string $name): Brand
    {
        return Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $companyId, 'name' => $name, 'is_active' => true]);
    }

    private function category(?int $companyId, string $name): ProductCategory
    {
        return ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $companyId, 'name' => $name, 'is_active' => true, 'sort_order' => 0]);
    }

    private function product(?int $companyId, Brand $brand, ProductCategory $category): Product
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $companyId,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Vital Blueprint',
            'price_satang' => 890000,
            'is_active' => true,
        ]);
    }

    private function tidy(array $options = []): void
    {
        $this->artisan('catalog:tidy-taxonomy', $options)->assertSuccessful();
    }

    // ── What it clears ───────────────────────────────────────────────

    public function test_it_removes_a_company_brand_left_unused_with_a_platform_twin(): void
    {
        // The report, exactly: the same word twice on a product form.
        $this->brand(null, 'De La Lita');
        $orphan = $this->brand($this->thaiLife->id, 'De La Lita');

        $this->tidy();

        $this->assertSoftDeleted('brands', ['id' => $orphan->id]);
    }

    public function test_it_removes_the_matching_category_too(): void
    {
        $this->category(null, 'Anti Aging');
        $orphan = $this->category($this->thaiLife->id, 'Anti Aging');

        $this->tidy();

        $this->assertSoftDeleted('product_categories', ['id' => $orphan->id]);
    }

    public function test_the_platform_row_is_the_one_that_survives(): void
    {
        // The whole point of choosing which of the pair to drop: everything
        // promoted now points at the platform row.
        $platform = $this->brand(null, 'De La Lita');
        $this->brand($this->thaiLife->id, 'De La Lita');

        $this->tidy();

        $this->assertNotSoftDeleted('brands', ['id' => $platform->id]);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->brand(null, 'De La Lita');
        $orphan = $this->brand($this->thaiLife->id, 'De La Lita');

        $this->tidy(['--dry-run' => true]);

        $this->assertNotSoftDeleted('brands', ['id' => $orphan->id]);
    }

    // ── What it refuses ──────────────────────────────────────────────

    public function test_a_brand_a_product_still_uses_is_kept(): void
    {
        // The failure that would matter: a product whose brand vanished.
        $platform = $this->brand(null, 'De La Lita');
        $inUse = $this->brand($this->thaiLife->id, 'De La Lita');
        $this->product($this->thaiLife->id, $inUse, $this->category($this->thaiLife->id, 'Anti Aging'));

        $this->tidy();

        $this->assertNotSoftDeleted('brands', ['id' => $inUse->id]);
        $this->assertNotSoftDeleted('brands', ['id' => $platform->id]);
    }

    public function test_a_brand_only_a_delete_d_product_uses_is_kept(): void
    {
        /*
         * A soft-deleted product still carries the brand_id it was sold under.
         * Counting only live products would clear the brand, and the admin who
         * later restores the product would find it pointing at a brand that is
         * itself deleted — a broken row created by a tidy-up.
         */
        $this->brand(null, 'De La Lita');
        $brand = $this->brand($this->thaiLife->id, 'De La Lita');
        $product = $this->product($this->thaiLife->id, $brand, $this->category($this->thaiLife->id, 'Anti Aging'));
        $product->delete();

        $this->tidy();

        $this->assertNotSoftDeleted('brands', ['id' => $brand->id]);
    }

    public function test_a_category_with_a_commission_rule_is_kept(): void
    {
        /*
         * TASK-028's category-scoped rate. Removing the category would make
         * the rule stop matching silently and send the sale to the company
         * default — a wrong payout written into an immutable ledger
         * (BR-2/BR-4). Same reason promotion skips these products entirely.
         */
        $this->category(null, 'Anti Aging');
        $category = $this->category($this->thaiLife->id, 'Anti Aging');

        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $this->thaiLife->id,
            'cert_tier_id' => CertTier::query()->first()?->id,
            'product_id' => null,
            'product_category_id' => $category->id,
            'rate_type' => 'percentage',
            'rate_value' => 10,
            'effective_from' => now()->toDateString(),
        ]);

        $this->tidy();

        $this->assertNotSoftDeleted('product_categories', ['id' => $category->id]);
    }

    public function test_a_company_brand_with_no_platform_twin_is_kept(): void
    {
        /*
         * The safety rail that makes this a de-duplication rather than a
         * cull: with nothing of the same name at platform level, removing the
         * row would take the name off the platform altogether.
         */
        $unique = $this->brand($this->thaiLife->id, 'Thai Life Exclusive');

        $this->tidy();

        $this->assertNotSoftDeleted('brands', ['id' => $unique->id]);
    }

    public function test_a_different_companys_row_is_untouched_when_one_company_is_named(): void
    {
        $other = Company::factory()->create(['name' => 'AIA']);
        $this->brand(null, 'De La Lita');
        $mine = $this->brand($this->thaiLife->id, 'De La Lita');
        $theirs = $this->brand($other->id, 'De La Lita');

        $this->tidy(['--company' => $this->thaiLife->id]);

        $this->assertSoftDeleted('brands', ['id' => $mine->id]);
        $this->assertNotSoftDeleted('brands', ['id' => $theirs->id]);
    }

    public function test_names_must_match_exactly(): void
    {
        /*
         * The same comparison promotion made. A looser match (trimmed,
         * case-folded) could delete a row that promotion never replaced,
         * leaving products pointing at a deleted brand.
         */
        $this->brand(null, 'De La Lita');
        $nearly = $this->brand($this->thaiLife->id, 'de la lita ');

        $this->tidy();

        $this->assertNotSoftDeleted('brands', ['id' => $nearly->id]);
    }

    public function test_it_writes_down_what_it_removed(): void
    {
        $this->brand(null, 'De La Lita');
        $orphan = $this->brand($this->thaiLife->id, 'De La Lita');

        $this->tidy();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'brand.tidied_after_promotion',
            'auditable_id' => $orphan->id,
        ]);
    }
}
