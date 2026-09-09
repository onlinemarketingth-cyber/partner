<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\ProductCategory;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use App\Services\Catalog\BrandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-09 (human: "Super admin เพิ่มสินค้าแล้วแต่ไม่มี UI ตรงไหนแจ้งไว้ว่าจะ
 * บันทึกลงเฉพาะ company หรือ ใช้เป็น product กลาง ทำแบบเดียวกันกับทางแบรนด์
 * และหมวดหมู่").
 *
 * ADR-040 made `company_id NULL` mean "the platform owns this and every company
 * uses it", and every READ was updated for it. The WRITES were not: all three
 * Store requests still demanded a concrete company from a Super Admin, so the
 * only thing that could ever create a platform row was
 * `catalog:promote-products` — a command, run over the shoulder of somebody
 * with SSH. A Super Admin adding a product on screen could neither say what
 * they were making nor find out what they had made.
 *
 * The product case carries two invariants a brand and a category do not, and
 * both are about money:
 *
 *   A PLATFORM PRODUCT MUST NAME ITS COMMISSION PLAN TYPE. A company product
 *   with none inherits its company's; a platform product has no company, and
 *   Product::effectivePlanType() throws rather than guess, because the plan
 *   type decides HOW commission is computed and a guess lands in a ledger that
 *   cannot be corrected (BR-2/BR-4).
 *
 *   A PLATFORM PRODUCT CANNOT CARRY A PIPELINE TEMPLATE. Templates belong to
 *   one company; the journey resolves per company instead (ADR-026 §3.3).
 */
class CreatePlatformRowFromAdminTest extends TestCase
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

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /** @param  array<string, mixed>  $overrides */
    private function platformProductPayload(array $overrides = []): array
    {
        return array_merge([
            'is_platform' => true,
            'brand_id' => $this->platformBrand->id,
            'category_id' => $this->platformCategory->id,
            'name' => 'Vital Blueprint',
            'price_satang' => 890000,
            'commission_plan_type' => 'unilevel',
        ], $overrides);
    }

    // ── Products ─────────────────────────────────────────────────────

    public function test_a_super_admin_creates_a_product_the_whole_platform_owns(): void
    {
        // The thing that had no route through the UI at all.
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $this->platformProductPayload())
            ->assertCreated()
            ->assertJsonPath('data.company_id', null);

        $this->assertDatabaseHas('products', ['name' => 'Vital Blueprint', 'company_id' => null]);
    }

    public function test_a_platform_product_must_name_its_commission_plan(): void
    {
        /*
         * The invariant Product::effectivePlanType() throws to protect. Asking
         * here is the cheapest place to keep "unreachable" actually
         * unreachable, rather than discovering it at the moment a commission
         * is calculated.
         */
        $payload = $this->platformProductPayload();
        unset($payload['commission_plan_type']);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('commission_plan_type');
    }

    public function test_a_company_product_still_may_inherit_its_plan(): void
    {
        // Unchanged for every product that exists today: no plan type means
        // "use my company's", and the company is there to ask.
        $payload = $this->platformProductPayload(['is_platform' => false, 'company_id' => $this->aia->id]);
        unset($payload['commission_plan_type']);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $payload)
            ->assertCreated();
    }

    public function test_a_platform_product_may_not_carry_a_pipeline_template(): void
    {
        // A template belongs to one company; a shared product belongs to none.
        $template = PipelineTemplate::query()->where('company_id', $this->aia->id)->first()
            ?? PipelineTemplate::factory()->create(['company_id' => $this->aia->id]);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $this->platformProductPayload([
                'pipeline_template_id' => $template->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pipeline_template_id');
    }

    public function test_a_platform_product_may_not_borrow_one_companys_brand(): void
    {
        /*
         * It belongs to nobody, so it cannot wear one customer's brand — every
         * other company would see that name on a product they sell. Falls out
         * of ValidatesProductTaxonomy: with no company, the same rule collapses
         * to "platform rows only".
         */
        $companyBrand = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'AIA Only', 'is_active' => true]);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $this->platformProductPayload(['brand_id' => $companyBrand->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_id');
    }

    public function test_company_id_is_no_longer_demanded_when_the_row_is_the_platforms(): void
    {
        // The rule that used to make this impossible: company_id was required
        // of a Super Admin unconditionally.
        $payload = $this->platformProductPayload();
        $this->assertArrayNotHasKey('company_id', $payload);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $payload)
            ->assertCreated();
    }

    public function test_company_id_is_still_demanded_for_a_company_product(): void
    {
        $payload = $this->platformProductPayload(['is_platform' => false]);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/products', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');
    }

    // ── Brands and categories ────────────────────────────────────────

    public function test_a_super_admin_creates_a_platform_brand(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/brands', ['is_platform' => true, 'name' => 'De La Lita'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', null);
    }

    public function test_a_super_admin_creates_a_platform_category(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/product-categories', ['is_platform' => true, 'name' => 'Life Style'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', null);
    }

    // ── Who may not ──────────────────────────────────────────────────

    public function test_a_company_admin_asking_for_a_platform_brand_gets_their_own(): void
    {
        /*
         * Stripped, not rejected: they were never shown the control, so a 422
         * about it would answer a question they did not ask. What they get is
         * exactly what they got before — a brand belonging to their company.
         */
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $this->actingAs($actor)
            ->postJson('/api/v1/brands', ['is_platform' => true, 'name' => 'Sneaky'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->aia->id);
    }

    public function test_a_company_admin_asking_for_a_platform_product_gets_their_own(): void
    {
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);
        $ownBrand = Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'AIA Only', 'is_active' => true]);
        $ownCategory = ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->create(['company_id' => $this->aia->id, 'name' => 'AIA Cat', 'is_active' => true, 'sort_order' => 0]);

        $this->actingAs($actor)
            ->postJson('/api/v1/products', [
                'is_platform' => true,
                'brand_id' => $ownBrand->id,
                'category_id' => $ownCategory->id,
                'name' => 'Sneaky Product',
                'price_satang' => 100000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->aia->id);
    }

    public function test_the_service_refuses_a_company_admin_even_off_the_http_path(): void
    {
        /*
         * The Form Request guards one route; a row created here is visible to
         * every tenant on the system, so the Service asks again.
         */
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $brand = app(BrandService::class)
            ->create(['is_platform' => true, 'name' => 'Off Path'], $actor);

        $this->assertSame($this->aia->id, $brand->company_id);
    }
}
