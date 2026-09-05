<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\CatalogBrand;
use App\Models\CatalogCategory;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCatalogItem;
use App\Models\ProductCategory;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Catalog\ProductCatalogPropagationService;
use App\Services\Platform\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-251 — "สินค้าใช้ร่วมกันทุกบริษัท" (human decision 2026-09-04).
 *
 * ADR-036 built the shared catalog and left LINKING as a deliberate,
 * one-company-at-a-time action. The human asked for the other default: a
 * product added to the catalog is simply in every company. This file pins
 * that behaviour and — more importantly — the four limits on it, because a
 * feature that writes rows into every tenant at once is only safe if each of
 * these holds:
 *
 *   1. every propagated listing arrives DISABLED. Anything else is a product
 *      on sale in a company whose admin has never seen it.
 *   2. the price is copied ONCE, from the catalog's default. After that it is
 *      that company's price and nothing may overwrite it — least of all a
 *      re-run of the propagation.
 *   3. it is idempotent. Three callers reach it (item created, company
 *      created, backfill command) and two can fire for the same pair.
 *   4. BR-6 still holds: each listing carries ITS OWN company_id, and every
 *      per-company thing (brand/category join rows, commission) stays put.
 */
class SharedCatalogPropagationTest extends TestCase
{
    use RefreshDatabase;

    private function catalogItem(?int $defaultPriceSatang = 890000): ProductCatalogItem
    {
        return ProductCatalogItem::factory()->create([
            'catalog_brand_id' => CatalogBrand::factory(),
            'catalog_category_id' => CatalogCategory::factory(),
            'default_price_satang' => $defaultPriceSatang,
        ]);
    }

    /** Every company's listing of one catalog item, TenantScope out of the way. */
    private function listings(ProductCatalogItem $item)
    {
        return Product::withoutGlobalScope(TenantScope::class)
            ->where('catalog_item_id', $item->id)
            ->get();
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    // ── Creating a catalog item reaches every company ──────────────────

    public function test_creating_a_catalog_item_gives_every_company_a_listing(): void
    {
        $thaiLife = Company::factory()->create();
        $genesenn = Company::factory()->create();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/product-catalog-items', [
                'catalog_brand_id' => CatalogBrand::factory()->create()->id,
                'catalog_category_id' => CatalogCategory::factory()->create()->id,
                'name' => 'Vital Blueprint V5',
                'default_price_satang' => 890000,
            ])
            ->assertCreated();

        $item = ProductCatalogItem::firstOrFail();
        $listings = $this->listings($item);

        $this->assertCount(2, $listings);
        $this->assertEqualsCanonicalizing(
            [$thaiLife->id, $genesenn->id],
            $listings->pluck('company_id')->all(),
        );
    }

    public function test_every_new_listing_starts_switched_off(): void
    {
        /*
         * Limit 1, and the reason the whole feature is safe. Propagation
         * reaches companies whose admins have never heard of this product; a
         * listing that goes live by itself is a product on sale that nobody
         * chose to sell, at a price nobody in that company approved.
         */
        Company::factory()->count(2)->create();

        $item = $this->catalogItem();
        $this->propagate($item);

        $this->assertCount(2, $this->listings($item));
        $this->assertTrue($this->listings($item)->every(fn (Product $p) => $p->is_active === false));
    }

    public function test_the_listing_is_created_at_the_catalogs_default_price(): void
    {
        Company::factory()->create();

        $item = $this->catalogItem(1234500);
        $this->propagate($item);

        // BR-3 — integer satang all the way through, no float anywhere.
        $this->assertSame(1234500, (int) $this->listings($item)->first()->price_satang);
    }

    public function test_an_item_with_no_default_price_reaches_nobody(): void
    {
        /*
         * BR-7. There is no honest price to create a row with, and 0 บาท is a
         * number a person reads as a decision. Silence is the correct
         * outcome — not a listing at zero, and not a crash.
         */
        Company::factory()->create();

        $item = $this->catalogItem(null);

        $this->assertSame([], $this->propagate($item));
        $this->assertCount(0, $this->listings($item));
    }

    // ── Idempotence, and never overwriting a company's own price ───────

    public function test_running_it_again_creates_nothing(): void
    {
        // Limit 3. Two of the three callers can fire for the same pair.
        Company::factory()->count(2)->create();

        $item = $this->catalogItem();
        $this->propagate($item);
        $this->propagate($item);

        $this->assertCount(2, $this->listings($item));
    }

    public function test_a_company_that_changed_its_price_keeps_it(): void
    {
        /*
         * Limit 2, the one that would cost real money. ADR-036 §3: price is
         * per company. A second propagation that "refreshed" the price would
         * quietly undo a decision somebody made — and would do it to every
         * company at once, hours or months later, with no visible trigger.
         */
        Company::factory()->create();

        $item = $this->catalogItem(890000);
        $this->propagate($item);

        $listing = $this->listings($item)->first();
        $listing->forceFill(['price_satang' => 990000, 'is_active' => true])->save();

        $this->propagate($item);

        $listing->refresh();
        $this->assertSame(990000, (int) $listing->price_satang);
        // …and the company's decision to switch it ON is equally untouched.
        $this->assertTrue($listing->is_active);
    }

    public function test_a_company_that_deleted_its_listing_does_not_get_it_back(): void
    {
        // Removing a listing is a decision too. Resurrecting it on the next
        // run would make the delete button a suggestion.
        Company::factory()->create();

        $item = $this->catalogItem();
        $this->propagate($item);

        $this->listings($item)->first()->delete();
        $this->propagate($item);

        $this->assertCount(0, $this->listings($item));
        $this->assertCount(1, Product::withoutGlobalScope(TenantScope::class)->withTrashed()->get());
    }

    // ── A new company gets the catalog it missed ───────────────────────

    public function test_a_company_created_later_still_gets_every_catalog_item(): void
    {
        $first = $this->catalogItem();
        $second = $this->catalogItem();

        $newcomer = Company::factory()->create();
        app(CompanyService::class)->create([
            'name' => 'Genesenn', 'slug' => 'genesenn-'.uniqid(),
        ]);

        // The company created THROUGH the service is the one under test; the
        // factory row above exists only so "every company" means more than one.
        $provisioned = Company::where('name', 'Genesenn')->firstOrFail();

        $listings = Product::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $provisioned->id)
            ->get();

        $this->assertCount(2, $listings);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $listings->pluck('catalog_item_id')->all(),
        );
        $this->assertTrue($listings->every(fn (Product $p) => $p->is_active === false));
        $this->assertNotSame($newcomer->id, $provisioned->id);
    }

    // ── BR-6 and the per-company machinery ────────────────────────────

    public function test_each_listing_gets_its_own_companys_brand_and_category(): void
    {
        /*
         * TASK-206's lesson, applied to rows nobody created by hand. A linked
         * product with a null category_id silently loses its category-scoped
         * commission rule — a WRONG PAYOUT (BR-2) written to an immutable
         * ledger (BR-4) — plus its pipeline template and its findability by
         * brand. Propagated rows go through the same link service precisely so
         * they cannot miss what a hand-linked row gets.
         */
        $a = Company::factory()->create();
        $b = Company::factory()->create();

        $item = $this->catalogItem();
        $this->propagate($item);

        foreach ([$a, $b] as $company) {
            $listing = $this->listings($item)->firstWhere('company_id', $company->id);

            $this->assertNotNull($listing->brand_id);
            $this->assertNotNull($listing->category_id);

            // BR-6 — the join rows belong to the LISTING's company, never to
            // the acting Super Admin and never to a shared global row.
            $this->assertSame(
                $company->id,
                Brand::withoutGlobalScope(TenantScope::class)->find($listing->brand_id)->company_id,
            );
            $this->assertSame(
                $company->id,
                ProductCategory::withoutGlobalScope(TenantScope::class)->find($listing->category_id)->company_id,
            );
        }
    }

    public function test_no_commission_is_invented_for_the_new_listings(): void
    {
        // The human was asked whether the catalog should carry a central
        // commission default and said no: commission stays per company, where
        // it already lives (BR-2). A propagated row therefore has no rule of
        // its own and falls back exactly like any hand-made product.
        Company::factory()->create();

        $item = $this->catalogItem();
        $this->propagate($item);

        $listing = $this->listings($item)->first();

        $this->assertNull($listing->commission_plan_type);
        $this->assertSame(0, $listing->commissionRules()->count());
    }

    public function test_a_company_admin_still_cannot_edit_a_catalog_linked_product(): void
    {
        // ADR-036 §5 is unchanged by TASK-251: propagation puts the row in
        // their catalog, it does not hand them the shared identity.
        $company = Company::factory()->create();
        $item = $this->catalogItem();
        $this->propagate($item);

        $listing = $this->listings($item)->firstWhere('company_id', $company->id);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$listing->id}", ['price_satang' => 100])
            ->assertForbidden();
    }

    public function test_the_propagation_is_recorded(): void
    {
        /*
         * Section 6. One person saving one form makes a product appear in
         * every company's catalog; without a row saying so, that is
         * unexplainable afterwards.
         */
        Company::factory()->count(2)->create();

        $item = $this->catalogItem();
        $this->propagate($item);

        $row = AuditLog::where('action', 'catalog_item.propagated')->firstOrFail();

        $this->assertSame(2, $row->new_values['company_count']);
        $this->assertFalse($row->new_values['is_active']);
        // Cross-company by nature: pinning it to one tenant would hide it
        // from the others' audit screens.
        $this->assertNull($row->company_id);
    }

    /**
     * @return list<int>
     */
    private function propagate(ProductCatalogItem $item): array
    {
        return app(ProductCatalogPropagationService::class)
            ->propagateItemToAllCompanies($item);
    }
}
