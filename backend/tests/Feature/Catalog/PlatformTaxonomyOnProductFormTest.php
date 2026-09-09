<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-09 (human: "ตอนนี้แบรนด์เหลืออันเดียวแล้ว และหมวดหมู่ด้วย แต่บันทึกแล้ว
 * error" — "The selected brand id is invalid.").
 *
 * The picker offered a brand the save then refused.
 *
 * `BrandController::index` has returned platform-owned brands to every company
 * since TASK-253 (`includePlatformWide: true`) — that is the whole point of a
 * brand with `company_id NULL`. But the three product Form Requests still
 * validated with an exact match on the owning company, written by hand in each
 * of them, so a platform brand failed `exists`.
 *
 * It stayed invisible while every company still had a same-named brand of its
 * own to pick instead. `catalog:tidy-taxonomy` cleared those away — correctly —
 * and the gap became total: AIA was left unable to create ANY product, because
 * every brand the form offered it was one the save refused.
 *
 * A rule that disagrees with the list beside it is worse than either being
 * wrong alone: the screen tells you the answer and then rejects it.
 */
class PlatformTaxonomyOnProductFormTest extends TestCase
{
    use RefreshDatabase;

    private Company $aia;

    private Brand $platformBrand;

    private ProductCategory $platformCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aia = Company::factory()->create(['name' => 'AIA']);

        $this->platformBrand = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => null, 'name' => 'Genesenn', 'is_active' => true]);

        $this->platformCategory = ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => null, 'name' => 'Anti Aging', 'is_active' => true, 'sort_order' => 0]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->aia->id,
            'brand_id' => $this->platformBrand->id,
            'category_id' => $this->platformCategory->id,
            'name' => 'GENESENN 1-Year Vital Blueprint | Advanced Hormone Profile',
            'price_satang' => 2990000,
        ], $overrides);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    // ── The report ───────────────────────────────────────────────────

    public function test_a_company_may_create_a_product_under_a_platform_brand(): void
    {
        // Exactly the save that failed: AIA, platform brand, platform category.
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('products', [
            'company_id' => $this->aia->id,
            'brand_id' => $this->platformBrand->id,
            'category_id' => $this->platformCategory->id,
        ]);
    }

    public function test_a_company_admin_can_do_it_for_their_own_company(): void
    {
        // Not a Super-Admin-only capability: the picker shows platform brands
        // to a Company Admin too, so the save has to accept them.
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        // No company_id at all — they are forced to their own, and sending the
        // key would be answering a question they are not asked.
        $payload = $this->payload();
        unset($payload['company_id']);

        $this->actingAs($actor)
            ->postJson('/api/v1/products', $payload)
            ->assertCreated();
    }

    public function test_a_companys_own_brand_still_works(): void
    {
        // The regression guard: every product that exists today took this
        // branch, and nothing about it changed.
        $own = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'AIA Only', 'is_active' => true]);
        $ownCategory = ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'AIA Category', 'is_active' => true, 'sort_order' => 0]);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $this->payload(['brand_id' => $own->id, 'category_id' => $ownCategory->id]))
            ->assertCreated();
    }

    // ── What must still be refused ───────────────────────────────────

    public function test_another_companys_brand_is_still_refused(): void
    {
        /*
         * BR-6, and the reason the old rule existed. Widening it to "any
         * brand" would have been the easy fix and would let a product carry
         * another tenant's brand — which is a data leak wearing a label.
         */
        $other = Company::factory()->create(['name' => 'Thai Life']);
        $theirs = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $other->id, 'name' => 'Thai Life Only', 'is_active' => true]);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $this->payload(['brand_id' => $theirs->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_id');
    }

    public function test_a_deleted_brand_is_refused(): void
    {
        /*
         * `Rule::exists` reads the TABLE, not the model, so a soft-deleted row
         * satisfied it. Survivable while nothing deleted brands —
         * `catalog:tidy-taxonomy` now deletes them by the dozen, and every one
         * of those ids would otherwise still have validated.
         */
        $this->platformBrand->delete();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_id');
    }

    public function test_a_deleted_category_is_refused_too(): void
    {
        $this->platformCategory->delete();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    // ── Editing, not just creating ───────────────────────────────────

    public function test_an_existing_product_can_be_moved_onto_a_platform_brand(): void
    {
        // Same rule, same three files: the update path had the identical
        // hand-written check and the identical hole.
        $own = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'AIA Only', 'is_active' => true]);
        $ownCategory = ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'AIA Category', 'is_active' => true, 'sort_order' => 0]);

        $product = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $this->aia->id,
            'brand_id' => $own->id,
            'category_id' => $ownCategory->id,
            'name' => 'Existing',
            'price_satang' => 100000,
            'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin())
            ->putJson("/api/v1/products/{$product->id}", [
                'brand_id' => $this->platformBrand->id,
                'category_id' => $this->platformCategory->id,
            ])
            ->assertOk();

        $this->assertSame($this->platformBrand->id, $product->refresh()->brand_id);
    }
}
