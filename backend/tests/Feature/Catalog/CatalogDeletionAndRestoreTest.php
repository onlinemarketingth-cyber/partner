<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\CertTier;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Referral;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-09 (human: "ตอนนี้ไม่มี Ui ลบสินค้ากลาง กับสินค้าจาก company ผู้ใช้
 * ไม่ทราบ ควรแยกกัน").
 *
 * Deleting a company's product and deleting a PLATFORM product are two
 * different acts wearing the same grey trash icon and the same sentence. One
 * hides a package from one company; the other takes it off every storefront
 * on the system at once. Nothing on screen said which one you were about to
 * do, and nothing could: the row had no idea how many companies were selling
 * it.
 *
 * ── WHAT STOPS A DELETE, AND WHAT ONLY WARNS ──
 *
 * The human's ruling, asked and answered on 2026-09-09: "เตือนหากยังไม่มี
 * การขายเกิดขึ้น ห้ามลบกรณีมีการขายเกิดขึ้นแล้ว".
 *
 *   BLOCKERS are HISTORY — a sale, a commission ledger row. Money that
 *   already moved (BR-4). Hiding the product they name would leave a paid
 *   commission describing a package no report can resolve.
 *
 *   WARNINGS are STATE — "three companies have this switched on". A switch
 *   is not history; making them flip three switches off first would be
 *   ceremony, not safety. But it must never be a surprise, so the companies
 *   are NAMED.
 *
 * ── AND THE OTHER HALF: NOTHING COULD BE UNDONE ──
 *
 * Every catalogue delete has been soft since TASK-091, and no screen could
 * bring a row back — the data sat there and the only route to it was a
 * hand-written UPDATE against production. So the dialog could not honestly
 * promise "กู้คืนได้" either.
 */
class CatalogDeletionAndRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Company $aia;

    private Company $thaiLife;

    private Product $shared;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aia = Company::factory()->create(['name' => 'AIA']);
        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);

        $this->shared = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'name' => 'GENESENN 1-Year Vital Blueprint',
            'price_satang' => 2990000,
            'is_active' => true,
            'commission_plan_type' => 'unilevel',
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /** A sale that actually happened — the thing a delete must never orphan. */
    private function recordASale(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);

        Referral::factory()->create([
            'company_id' => $this->aia->id,
            'agent_id' => $agent->id,
            'product_id' => $this->shared->id,
        ]);
    }

    private function sellAt(Company $company): void
    {
        CompanyProductSetting::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $company->id,
            'product_id' => $this->shared->id,
            'is_active' => true,
        ]);
    }

    // ── The dialog can finally say something true ────────────────────

    public function test_the_impact_of_deleting_a_shared_product_names_every_company_selling_it(): void
    {
        /*
         * The sentence the screen could not write. Two companies are selling
         * this; neither is necessarily the one the admin is scoped to, and
         * both lose it the moment the row is hidden.
         */
        $this->sellAt($this->aia);
        $this->sellAt($this->thaiLife);

        $this->actingAs($this->superAdmin())
            ->getJson("/api/v1/products/{$this->shared->id}/deletion-impact")
            ->assertOk()
            ->assertJsonPath('data.is_shared', true)
            ->assertJsonPath('data.selling_companies', ['AIA', 'Thai Life']);
    }

    public function test_a_company_that_switched_it_off_is_not_counted(): void
    {
        // is_active false is "not on sale here" — naming them would inflate
        // the warning, and a warning nobody believes is worse than none.
        CompanyProductSetting::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $this->aia->id,
            'product_id' => $this->shared->id,
            'is_active' => false,
        ]);

        $this->actingAs($this->superAdmin())
            ->getJson("/api/v1/products/{$this->shared->id}/deletion-impact")
            ->assertOk()
            ->assertJsonPath('data.selling_companies', []);
    }

    public function test_a_company_product_reports_no_other_companies(): void
    {
        // The everyday case, and the reason the dialog has two shapes: there
        // is exactly one company and the admin is already looking at it.
        $own = Product::factory()->create(['company_id' => $this->aia->id]);

        $this->actingAs($this->superAdmin())
            ->getJson("/api/v1/products/{$own->id}/deletion-impact")
            ->assertOk()
            ->assertJsonPath('data.is_shared', false)
            ->assertJsonPath('data.selling_companies', []);
    }

    public function test_asking_what_a_delete_would_cost_needs_the_right_to_perform_it(): void
    {
        // A Company Admin cannot delete a shared product (ProductPolicy), so
        // they are not told what deleting it would do to other companies
        // either — the answer is a list of other tenants' names.
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($actor)
            ->getJson("/api/v1/products/{$this->shared->id}/deletion-impact")
            ->assertForbidden();
    }

    // ── Warn, but do not block ───────────────────────────────────────

    public function test_a_shared_product_being_sold_can_still_be_deleted(): void
    {
        // The human's ruling: a switch is not a sale. The warning is the
        // safeguard here, not a refusal.
        $this->sellAt($this->aia);
        $this->sellAt($this->thaiLife);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/v1/products/{$this->shared->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $this->shared->id]);
    }

    public function test_a_product_that_has_actually_been_sold_cannot(): void
    {
        // History, not state. A referral is a sale that happened.
        $this->recordASale();

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/v1/products/{$this->shared->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('id');

        $this->assertNotSoftDeleted('products', ['id' => $this->shared->id]);
    }

    public function test_the_impact_endpoint_and_the_delete_agree_about_why(): void
    {
        /*
         * The property this whole design rests on: the dialog is drawn from
         * the same computation that enforces the refusal, so it can never
         * promise a delete the server then refuses (or warn about a refusal
         * that never comes).
         */
        $this->recordASale();

        $impact = $this->actingAs($this->superAdmin())
            ->getJson("/api/v1/products/{$this->shared->id}/deletion-impact")
            ->assertOk()
            ->json('data.blockers');

        $this->assertSame(1, $impact['Referral / การขาย']);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/v1/products/{$this->shared->id}")
            ->assertStatus(422);
    }

    // ── Restore ──────────────────────────────────────────────────────

    public function test_a_deleted_product_can_be_brought_back(): void
    {
        $this->shared->delete();

        $this->actingAs($this->superAdmin())
            ->postJson("/api/v1/products/{$this->shared->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $this->shared->id);

        $this->assertNotSoftDeleted('products', ['id' => $this->shared->id]);
    }

    public function test_the_prices_each_company_set_survive_the_round_trip(): void
    {
        /*
         * What makes "hide" honest rather than a euphemism. company_product_
         * settings is not cascaded by a soft delete, so a restored product
         * comes back on sale at the same price it left — the admin does not
         * have to reconstruct three companies' pricing from memory.
         */
        CompanyProductSetting::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $this->aia->id,
            'product_id' => $this->shared->id,
            'price_satang' => 2500000,
            'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin())->deleteJson("/api/v1/products/{$this->shared->id}")->assertNoContent();
        $this->actingAs($this->superAdmin())->postJson("/api/v1/products/{$this->shared->id}/restore")->assertOk();

        $this->assertDatabaseHas('company_product_settings', [
            'company_id' => $this->aia->id,
            'product_id' => $this->shared->id,
            'price_satang' => 2500000,
            'is_active' => true,
        ]);
    }

    public function test_a_company_admin_cannot_restore_a_platform_product(): void
    {
        // Un-deleting is the same authority as deleting, deliberately.
        $this->shared->delete();
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($actor)
            ->postJson("/api/v1/products/{$this->shared->id}/restore")
            ->assertForbidden();
    }

    public function test_a_company_admin_can_restore_their_own_product(): void
    {
        $own = Product::factory()->create(['company_id' => $this->aia->id]);
        $own->delete();
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($actor)
            ->postJson("/api/v1/products/{$own->id}/restore")
            ->assertOk();
    }

    public function test_a_company_admin_cannot_restore_another_companys_product(): void
    {
        // BR-6. The withTrashed() route binding widens which rows the URL can
        // reach, and it would be easy for it to widen tenancy with it.
        $theirs = Product::factory()->create(['company_id' => $this->thaiLife->id]);
        $theirs->delete();
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($actor)
            ->postJson("/api/v1/products/{$theirs->id}/restore")
            ->assertNotFound();
    }

    // ── The bin tab ──────────────────────────────────────────────────

    public function test_the_bin_lists_deleted_products_brands_and_categories(): void
    {
        $brand = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'ลบแล้ว', 'is_active' => true]);
        $category = ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'หมวดที่ลบ', 'is_active' => true, 'sort_order' => 0]);

        $this->shared->delete();
        $brand->delete();
        $category->delete();

        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/catalog-trash')
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonCount(1, 'data.brands')
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.products.0.permissions.restore', true);
    }

    public function test_the_bin_never_shows_rows_that_are_not_deleted(): void
    {
        // The failure that would be quietest and worst: a bin listing live
        // catalogue rows, one click away from "restoring" something that was
        // never gone.
        Product::factory()->create(['company_id' => $this->aia->id]);

        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/catalog-trash')
            ->assertOk()
            ->assertJsonCount(0, 'data.products');
    }

    public function test_a_company_admin_sees_their_own_deleted_rows_and_not_another_companys(): void
    {
        $mine = Product::factory()->create(['company_id' => $this->aia->id, 'name' => 'ของฉัน']);
        $theirs = Product::factory()->create(['company_id' => $this->thaiLife->id, 'name' => 'ของเขา']);
        $mine->delete();
        $theirs->delete();

        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $response = $this->actingAs($actor)->getJson('/api/v1/catalog-trash')->assertOk();

        $names = array_column($response->json('data.products'), 'name');
        $this->assertContains('ของฉัน', $names);
        $this->assertNotContains('ของเขา', $names);
    }

    public function test_a_company_admin_is_told_they_may_not_restore_a_platform_row(): void
    {
        /*
         * The bin DOES show the shared row (SharedOrTenantScope) — hiding it
         * would make the catalogue look as though the product simply ceased
         * to exist. What it must not do is offer a button that 403s, so the
         * row carries the server's own answer.
         */
        $this->shared->delete();
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($actor)
            ->getJson('/api/v1/catalog-trash')
            ->assertOk()
            ->assertJsonPath('data.products.0.permissions.restore', false);
    }

    public function test_an_agent_may_not_look_in_the_bin_at_all(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);

        $this->actingAs($agent)->getJson('/api/v1/catalog-trash')->assertForbidden();
    }

    // ── Brands and categories get the same treatment ─────────────────

    public function test_a_brand_still_holding_products_cannot_be_deleted_and_says_so(): void
    {
        $brand = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => null, 'name' => 'Genesenn', 'is_active' => true]);
        Product::factory()->create(['company_id' => $this->aia->id, 'brand_id' => $brand->id]);

        $this->actingAs($this->superAdmin())
            ->getJson("/api/v1/brands/{$brand->id}/deletion-impact")
            ->assertOk()
            ->assertJsonPath('data.is_shared', true)
            ->assertJsonPath('data.blockers.สินค้า', 1);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/v1/brands/{$brand->id}")
            ->assertStatus(422);
    }

    public function test_a_deleted_brand_and_category_can_be_brought_back(): void
    {
        $brand = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => null, 'name' => 'Genesenn', 'is_active' => true]);
        $category = ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => null, 'name' => 'Anti Aging', 'is_active' => true, 'sort_order' => 0]);
        $brand->delete();
        $category->delete();

        $this->actingAs($this->superAdmin())->postJson("/api/v1/brands/{$brand->id}/restore")->assertOk();
        $this->actingAs($this->superAdmin())->postJson("/api/v1/product-categories/{$category->id}/restore")->assertOk();

        $this->assertNotSoftDeleted('brands', ['id' => $brand->id]);
        $this->assertNotSoftDeleted('product_categories', ['id' => $category->id]);
    }

    public function test_a_category_with_a_commission_rule_is_still_refused(): void
    {
        // The category blocker that is not about products at all — a rule
        // attached to a hidden category stops matching, and the sale quietly
        // pays the company default into an immutable ledger (BR-2/BR-4).
        $category = ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'Anti Aging', 'is_active' => true, 'sort_order' => 0]);
        $tier = CertTier::factory()->create();
        CommissionRule::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $this->aia->id,
            'cert_tier_id' => $tier->id,
            'product_category_id' => $category->id,
            'rate_type' => 'percentage',
            'rate_value' => 500,
            'effective_from' => now()->toDateString(),
        ]);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/v1/product-categories/{$category->id}")
            ->assertStatus(422);
    }
}
