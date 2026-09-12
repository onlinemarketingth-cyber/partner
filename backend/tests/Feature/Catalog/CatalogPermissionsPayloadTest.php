<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCatalogItem;
use App\Models\ProductCategory;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-245 — the API says what each button is allowed to do, instead of the
 * screen guessing.
 *
 * An audit of the admin console found eight controls a Company Admin could
 * click that the server would refuse. None of them was a missing rule — every
 * refusal was correctly enforced. They were all the same authoring mistake:
 * the screen re-derived "may I?" from whatever field looked closest, and the
 * field was not the rule.
 *
 *   • the brand/category cards group rows BY NAME, so one card can hold this
 *     company's row and the platform's — and the edit reached both.
 *   • the product row tested `is_shared`, which cannot see a catalog-LINKED
 *     product: still owned by its company, still Super-Admin-only to write
 *     (ADR-036 §5/§6).
 *   • the commission screen tested nothing at all.
 *
 * So each resource now carries the Policy's own answer. These tests pin the
 * three answers to the three DIFFERENT rules behind them — in particular that
 * `set_commission_rule` is not `update` wearing another name.
 *
 * 2026-09-11 — the example that used to make that last point is gone: ADR-040
 * granted a Company Admin their own commission on a shared product, and the
 * owner has since decided commission rate configuration is Super Admin's
 * alone. The two flags now happen to agree for a Company Admin. They are
 * still computed from two different rules, and the tests below keep them
 * apart so the next move of either one is caught.
 */
class CatalogPermissionsPayloadTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $companyAdmin;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'Thai Life']);
        $this->companyAdmin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    private function brand(?int $companyId, string $name = 'Genesenn'): Brand
    {
        return Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $companyId, 'name' => $name, 'is_active' => true]);
    }

    private function category(?int $companyId, string $name = 'Anti Aging'): ProductCategory
    {
        return ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $companyId, 'name' => $name, 'is_active' => true, 'sort_order' => 0]);
    }

    private function product(?int $companyId, array $overrides = []): Product
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)->create(array_merge([
            'company_id' => $companyId,
            'brand_id' => $this->brand($companyId, 'B'.uniqid())->id,
            'category_id' => $this->category($companyId, 'C'.uniqid())->id,
            'name' => 'Vital Blueprint V5',
            'price_satang' => 890000,
            'is_active' => true,
        ], $overrides));
    }

    /** @return array<string, bool> */
    private function permissionsFor(User $actor, string $path): array
    {
        return $this->actingAs($actor)->getJson($path)->assertOk()->json('data.0.permissions');
    }

    // ── Brands: one card, two owners ─────────────────────────────────

    public function test_a_company_admin_may_not_write_the_platforms_brand(): void
    {
        // GAP 1 and 2. The catalogue's edit reached this row because it groups
        // by name; the API now says plainly that it is not theirs.
        $this->brand(null);

        $permissions = $this->permissionsFor($this->companyAdmin, '/api/v1/brands');

        $this->assertFalse($permissions['update']);
        $this->assertFalse($permissions['delete']);
    }

    public function test_a_company_admin_may_write_their_own_brand(): void
    {
        $this->brand($this->company->id);

        $permissions = $this->permissionsFor($this->companyAdmin, '/api/v1/brands');

        $this->assertTrue($permissions['update']);
        $this->assertTrue($permissions['delete']);
    }

    public function test_a_super_admin_may_write_the_platforms_brand(): void
    {
        $this->brand(null);

        $this->assertTrue($this->permissionsFor($this->superAdmin, '/api/v1/brands')['update']);
    }

    public function test_the_same_holds_for_a_platform_category(): void
    {
        // GAP 3 and 4 — identical shape, identical rule, so identical answer.
        $this->category(null);

        $permissions = $this->permissionsFor($this->companyAdmin, '/api/v1/product-categories');

        $this->assertFalse($permissions['update']);
        $this->assertFalse($permissions['delete']);
    }

    // ── Products: the field the screen was reading was the wrong one ──

    public function test_a_company_admin_may_not_write_a_shared_product(): void
    {
        $this->product(null);

        $permissions = $this->permissionsFor($this->companyAdmin, '/api/v1/products');

        $this->assertFalse($permissions['update']);
        $this->assertFalse($permissions['delete']);
    }

    public function test_a_catalog_linked_product_is_read_only_even_though_it_is_theirs(): void
    {
        /*
         * GAP 5, and the reason the payload exists at all. This product's
         * company_id IS this company — `is_shared` is false — and the screen
         * therefore offered delete. ProductPolicy::update refuses it anyway
         * (ADR-036 §5/§6: once linked to the shared catalogue, every write to
         * the row is Super Admin's).
         */
        $item = ProductCatalogItem::factory()->create();
        $this->product($this->company->id, ['catalog_item_id' => $item->id]);

        $permissions = $this->permissionsFor($this->companyAdmin, '/api/v1/products');

        $this->assertFalse($permissions['update']);
        $this->assertFalse($permissions['delete']);
    }

    public function test_a_company_admin_may_write_their_own_standalone_product(): void
    {
        $this->product($this->company->id);

        $this->assertTrue($this->permissionsFor($this->companyAdmin, '/api/v1/products')['update']);
    }

    // ── Commission: a DIFFERENT question, and the ADR depends on it ───

    /**
     * 2026-09-11 (owner decision) — THIS TEST IS AN INVERSION. It used to be
     * test_a_company_admin_may_set_their_own_commission_on_a_shared_product
     * and it asserted `set_commission_rule` was TRUE.
     *
     * WHAT IT REPLACED: ADR-040 kept commission per company precisely so a
     * shared product could pay differently in each, and this flag was the one
     * answer that had to stay true while `update` was false. The owner has
     * now decided commission rate configuration is Super Admin's alone,
     * because a rate is money — which supersedes that right and, knowingly,
     * takes it away.
     *
     * The two assertions stay side by side because the ORIGINAL point of the
     * test survives the inversion: these are still two different questions
     * asked of two different rules. They merely now agree, by coincidence of
     * this decision, and a screen that starts deriving one from the other
     * would be wrong again the moment either moves.
     */
    public function test_a_company_admin_may_no_longer_set_commission_on_a_shared_product(): void
    {
        $this->product(null);

        $permissions = $this->permissionsFor($this->companyAdmin, '/api/v1/products');

        $this->assertFalse($permissions['update']);
        $this->assertFalse($permissions['set_commission_rule']);
    }

    /**
     * ...and not on their own standalone product either — the loss is total,
     * not limited to the shared catalogue. Recorded explicitly because the
     * inverted test above could otherwise be misread as "shared products are
     * the special case", which is exactly backwards now.
     */
    public function test_a_company_admin_may_no_longer_set_commission_on_their_own_product_either(): void
    {
        $this->product($this->company->id);

        $permissions = $this->permissionsFor($this->companyAdmin, '/api/v1/products');

        $this->assertTrue($permissions['update']);
        $this->assertFalse($permissions['set_commission_rule']);
    }

    public function test_a_company_admin_may_not_set_commission_on_a_catalog_linked_product(): void
    {
        // GAP 7 and 8 — StoreCommissionRuleRequest / UpdateCommissionRuleRequest
        // refuse exactly this, and nothing on the screen knew.
        $item = ProductCatalogItem::factory()->create();
        $this->product($this->company->id, ['catalog_item_id' => $item->id]);

        $this->assertFalse($this->permissionsFor($this->companyAdmin, '/api/v1/products')['set_commission_rule']);
    }

    public function test_a_super_admin_may_set_commission_on_anything(): void
    {
        $item = ProductCatalogItem::factory()->create();
        $this->product($this->company->id, ['catalog_item_id' => $item->id]);

        $this->assertTrue($this->permissionsFor($this->superAdmin, '/api/v1/products')['set_commission_rule']);
    }

    // ── The answer must match what actually happens ──────────────────

    public function test_the_payload_agrees_with_the_endpoint_that_refuses(): void
    {
        /*
         * The property that makes any of this worth having: `permissions` is
         * not a second opinion. If it ever drifted from the Policy, the screen
         * would be back to guessing — just with more confidence.
         */
        $brand = $this->brand(null);

        $this->assertFalse($this->permissionsFor($this->companyAdmin, '/api/v1/brands')['update']);

        $this->actingAs($this->companyAdmin)
            ->putJson("/api/v1/brands/{$brand->id}", ['name' => 'Renamed', 'is_active' => true])
            ->assertForbidden();
    }

    public function test_and_agrees_when_the_answer_is_yes(): void
    {
        $brand = $this->brand($this->company->id);

        $this->assertTrue($this->permissionsFor($this->companyAdmin, '/api/v1/brands')['update']);

        $this->actingAs($this->companyAdmin)
            ->putJson("/api/v1/brands/{$brand->id}", ['name' => 'Renamed', 'is_active' => true])
            ->assertOk();
    }
}
