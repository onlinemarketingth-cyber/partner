<?php

namespace Tests\Feature\Catalog;

use App\Enums\CommissionPlanType;
use App\Models\Brand;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-253 / ADR-040 — one product row every company uses, and the tenant
 * boundary that must survive it.
 *
 * This is the phase that changes BR-6, the highest-priority rule in the
 * codebase: product reads now include rows that belong to nobody. Every test
 * below exists because a mistake here is silent in one of two directions,
 * and both are worse than a crash:
 *
 *   WIDENING — company A starts seeing company B's own products. Nothing
 *   errors; a screen simply has more rows on it than it should, and the
 *   person reading it has no way to know.
 *
 *   NARROWING — a caller that used to bypass the scope quietly keeps it.
 *   Again nothing errors: a command or a report just returns fewer rows than
 *   the truth. That one is easy to cause by accident, because
 *   `withoutGlobalScope(TenantScope::class)` no longer removes this model's
 *   scope, so the OLD spelling compiles, runs, and lies.
 *
 * The written-out rule, for anyone reading this later:
 *   company_id = NULL  →  the platform owns it, everyone SELLS it, only a
 *                         Super Admin may EDIT it
 *   company_id = 4     →  exactly today's behaviour, unchanged
 */
class SharedProductIsolationTest extends TestCase
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

    /** A platform-owned product: no company, central price, shared taxonomy. */
    private function sharedProduct(string $name = 'Vital Blueprint V5', int $price = 890000): Product
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'brand_id' => Brand::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Genesenn (กลาง)', 'is_active' => true])->id,
            'category_id' => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Anti Aging (กลาง)', 'is_active' => true, 'sort_order' => 0])->id,
            'name' => $name,
            'price_satang' => $price,
            // A platform-owned product has no company to inherit a plan type
            // from, so it must state one (Product::effectivePlanType()).
            'commission_plan_type' => CommissionPlanType::Unilevel,
            'is_active' => true,
        ]);
    }

    private function companyProduct(Company $company, string $name): Product
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $company->id,
            'brand_id' => Brand::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => $company->id, 'name' => "Brand {$company->id}", 'is_active' => true])->id,
            'category_id' => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => $company->id, 'name' => "Cat {$company->id}", 'is_active' => true, 'sort_order' => 0])->id,
            'name' => $name,
            'price_satang' => 500000,
            'is_active' => true,
        ]);
    }

    // ── Reading: shared is visible, other tenants still are not ───────

    public function test_every_company_sees_the_platforms_product(): void
    {
        $this->sharedProduct();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $response = $this->actingAs($admin)->getJson('/api/v1/products')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_an_agent_sees_it_too(): void
    {
        // The whole point of the re-model: AIA's agents can sell it without
        // AIA owning a copy of it.
        $this->sharedProduct();
        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);

        $this->actingAs($agent)->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_another_companys_own_product_is_still_invisible(): void
    {
        /*
         * BR-6, unchanged and the reason this file exists. The scope now ends
         * in `OR company_id IS NULL`; if that OR were appended to the outer
         * query instead of nested inside its own closure, it would bind to
         * the whole preceding chain and let every tenant's rows through.
         * SharedOrTenantScope nests it — this test is what proves it stayed
         * nested.
         */
        $this->companyProduct($this->thaiLife, 'Thai Life Only');
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($admin)->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_company_sees_its_own_and_the_shared_one_together(): void
    {
        $this->sharedProduct('Shared One');
        $this->companyProduct($this->aia, 'AIA Only');
        $this->companyProduct($this->thaiLife, 'Thai Life Only');

        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $names = collect($this->actingAs($admin)->getJson('/api/v1/products')->json('data'))->pluck('name');

        $this->assertEqualsCanonicalizing(['Shared One', 'AIA Only'], $names->all());
    }

    public function test_a_super_admin_narrowed_to_one_company_still_sees_the_shared_product(): void
    {
        /*
         * The contradiction this prevents: without includePlatformWide, a
         * Company Admin (whose scope includes shared rows) would see the
         * product while a Super Admin who picked that same company would not
         * — the person with more authority seeing less, and reasonably
         * concluding the product is missing from that company.
         */
        $this->sharedProduct();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/products?company_id={$this->aia->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_route_model_binding_does_not_404_a_shared_product(): void
    {
        // Listed by the scope but 404 on the way in would be the most
        // confusing possible combination: the row is on screen and opening it
        // says it does not exist.
        $product = $this->sharedProduct();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($admin)->getJson("/api/v1/products/{$product->id}")->assertOk();
    }

    // ── Writing: reading a shared product is not owning it ────────────

    public function test_a_company_admin_cannot_edit_the_platforms_product(): void
    {
        // ADR-036 §5, re-confirmed by the human on 2026-09-05: "Super Admin
        // เท่านั้น". Their own price lives elsewhere, so this costs them
        // nothing they were promised.
        $product = $this->sharedProduct();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$product->id}", ['name' => 'Renamed by AIA'])
            ->assertForbidden();

        $this->assertSame('Vital Blueprint V5', $product->refresh()->name);
    }

    public function test_a_company_admin_cannot_delete_it_either(): void
    {
        $product = $this->sharedProduct();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/products/{$product->id}")->assertForbidden();
    }

    public function test_a_super_admin_can_edit_it(): void
    {
        $product = $this->sharedProduct();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}", ['name' => 'Vital Blueprint V6'])
            ->assertOk();

        $this->assertSame('Vital Blueprint V6', $product->refresh()->name);
    }

    public function test_a_company_admin_keeps_full_control_of_their_own_product(): void
    {
        // The narrowing must be scoped exactly to platform rows. A Company
        // Admin losing power over their own catalog would be a regression
        // dressed as a security improvement.
        $product = $this->companyProduct($this->aia, 'AIA Only');
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$product->id}", ['name' => 'AIA Renamed'])
            ->assertOk();
    }

    // ── The shared taxonomy behaves the same way ─────────────────────

    public function test_a_shared_brand_is_readable_by_everyone_and_writable_by_nobody_but_super_admin(): void
    {
        /*
         * A shared product points at a shared brand, so refusing the READ
         * would break the product it classifies. The WRITE stays central:
         * renaming it relabels every company at once, which is precisely why
         * one company must not be able to.
         */
        $brand = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => null, 'name' => 'Genesenn (กลาง)', 'is_active' => true]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($admin)->getJson('/api/v1/brands')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($admin)
            ->putJson("/api/v1/brands/{$brand->id}", ['name' => 'Renamed by AIA'])
            ->assertForbidden();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/brands/{$brand->id}", ['name' => 'Genesenn'])
            ->assertOk();
    }

    public function test_a_shared_category_behaves_the_same(): void
    {
        $category = ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => null, 'name' => 'Anti Aging (กลาง)', 'is_active' => true, 'sort_order' => 0]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($admin)->getJson('/api/v1/product-categories')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($admin)
            ->putJson("/api/v1/product-categories/{$category->id}", ['name' => 'Renamed'])
            ->assertForbidden();
    }

    // ── Per-company settings are per company ─────────────────────────

    public function test_one_companys_price_decision_is_invisible_to_another(): void
    {
        /*
         * The settings row is where the copy model's only legitimate content
         * went, and it must NOT inherit the product's shared visibility:
         * "what AIA charges" is AIA's business. Plain TenantScope on
         * CompanyProductSetting is what keeps that true.
         */
        $product = $this->sharedProduct();

        CompanyProductSetting::withoutGlobalScopes()->create([
            'company_id' => $this->thaiLife->id, 'product_id' => $product->id,
            'price_satang' => 990000, 'is_active' => true,
        ]);
        CompanyProductSetting::withoutGlobalScopes()->create([
            'company_id' => $this->aia->id, 'product_id' => $product->id,
            'price_satang' => 790000, 'is_active' => false,
        ]);

        $aiaAdmin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);
        $this->actingAs($aiaAdmin);

        $visible = CompanyProductSetting::all();

        $this->assertCount(1, $visible);
        $this->assertSame(790000, $visible->first()->price_satang);
    }

    public function test_a_company_cannot_hold_two_settings_rows_for_one_product(): void
    {
        // Two rows would be two prices for one listing with nothing to say
        // which is real — the exact ambiguity this whole re-model removes.
        $product = $this->sharedProduct();

        CompanyProductSetting::withoutGlobalScopes()->create([
            'company_id' => $this->aia->id, 'product_id' => $product->id, 'price_satang' => 790000,
        ]);

        $this->expectException(QueryException::class);

        CompanyProductSetting::withoutGlobalScopes()->create([
            'company_id' => $this->aia->id, 'product_id' => $product->id, 'price_satang' => 890000,
        ]);
    }

    public function test_a_settings_row_starts_switched_off(): void
    {
        // Inheriting a price is not deciding to sell. The earlier "ปิดไว้ก่อน"
        // decision survives the re-model, in the column default.
        $product = $this->sharedProduct();

        $setting = CompanyProductSetting::withoutGlobalScopes()->create([
            'company_id' => $this->aia->id, 'product_id' => $product->id,
        ]);

        $this->assertFalse($setting->refresh()->is_active);
        $this->assertNull($setting->price_satang);
    }

    // ── The trap this phase created, guarded ─────────────────────────

    public function test_no_caller_still_tries_to_bypass_the_old_scope_on_these_models(): void
    {
        /*
         * `Product::withoutGlobalScope(TenantScope::class)` still COMPILES and
         * still RUNS — it just does not remove SharedOrTenantScope any more,
         * so the query stays scoped and the caller silently gets fewer rows.
         * A console command that quietly processes a subset is not something
         * any behavioural test would catch, so this reads the source.
         *
         * If this fails: the fix is SharedOrTenantScope::class at the named
         * line, never adding the old scope back.
         */
        $offenders = [];
        // This file names the old spelling in its own docblock, on purpose —
        // the guidance is worth more than the scan's convenience.
        $selfPath = __FILE__;

        foreach (['app', 'tests', 'database'] as $dir) {
            $path = base_path($dir);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php' || $file->getPathname() === $selfPath) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                foreach (['Product', 'Brand', 'ProductCategory'] as $model) {
                    if (str_contains($source, $model.'::withoutGlobalScope(TenantScope::class)')) {
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).' ('.$model.')';
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "These call sites no longer remove the scope they name:\n".implode("\n", $offenders));
    }
}
