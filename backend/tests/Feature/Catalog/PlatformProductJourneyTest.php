<?php

namespace Tests\Feature\Catalog;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Pipeline\PipelineTemplateProvisioner;
use App\Services\Pipeline\PipelineTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-09 — reported from production: the buy button had vanished from a
 * live share link ("เช็คหน่อยระบบจ่ายเงินชำระเงินทำไมมันหายไปจากหน้านี้"),
 * followed by the ruling that decides this file: "เวลา set เป็นค่า product
 * กลาง ข้อมูลพื้นฐานที่ไม่ใช่ราคา กับค่าคอม นั้นต้องไปด้วยกันหมด".
 *
 * ── THE BUG, IN ONE SENTENCE ──
 *
 * `catalog:promote-products` cleared the product's journey on the reasoning
 * that a journey belongs to one company — so a promoted product fell through
 * to whatever the SELLING company had configured, and for a company with
 * nothing configured, to the Medical Package fail-safe, whose first step is
 * an appointment. The share page only offers checkout when payment is
 * reachable from the entry stage, so a Direct Sale product silently became
 * un-buyable from every link already in customers' hands.
 *
 * ── THE FIX IS THE ONE ADR-040 ALREADY MADE TWICE ──
 *
 * A journey can now be PLATFORM-owned (`company_id` null), exactly like a
 * platform brand and a platform category, and a shared product carries one.
 * Price and commission stay per company; everything else travels with the
 * product.
 *
 * What is pinned here is that boundary from both sides: a shared product may
 * hold a platform journey, and may NOT hold one company's.
 */
class PlatformProductJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Company $aia;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aia = Company::factory()->create(['name' => 'AIA']);
        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    /**
     * The platform journeys exist from the migration that introduced them —
     * this reads one back rather than making a second copy.
     */
    private function platformJourney(string $key): PipelineTemplate
    {
        return PipelineTemplate::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('key', $key)
            ->firstOrFail();
    }

    /**
     * A company's own copy of a system journey.
     *
     * Provisioned on demand: Company::factory() writes a bare row, while the
     * real CompanyService::create() calls the provisioner — so a test that
     * did not do this would be describing a company that cannot exist.
     * Idempotent, so calling it twice for one company is free.
     */
    private function companyJourney(Company $company, string $key): PipelineTemplate
    {
        app(PipelineTemplateProvisioner::class)->provision($company);

        return PipelineTemplate::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('key', $key)
            ->firstOrFail();
    }

    private function sharedProduct(?int $journeyId = null): Product
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'name' => 'GENESENN Health Tracker V5 Vital Blueprint',
            'price_satang' => 890000,
            'is_active' => true,
            'commission_plan_type' => 'unilevel',
            'pipeline_template_id' => $journeyId,
        ]);
    }

    // ── The platform journeys themselves ─────────────────────────────

    public function test_the_two_system_journeys_exist_at_platform_level(): void
    {
        /*
         * Created by migration, not by a seeder, and therefore present on
         * every database including a production one that is never seeded.
         * A shared product needs something to point AT before any of the
         * rest of this can work.
         */
        $direct = $this->platformJourney(PipelineTemplate::KEY_DIRECT_SALE_DEFAULT);
        $medical = $this->platformJourney(PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT);

        $this->assertSame(
            ['complete_registered', 'complete_payment'],
            array_map(fn ($stage) => $stage->value, $direct->stageSequence()),
        );
        $this->assertCount(5, $medical->stageSequence());
    }

    public function test_a_platform_journey_resolves_for_any_company(): void
    {
        // The whole point of platform ownership: ONE journey, every company.
        $thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        $product = $this->sharedProduct($this->platformJourney(PipelineTemplate::KEY_DIRECT_SALE_DEFAULT)->id);
        $resolver = app(PipelineTemplateResolver::class);

        foreach ([$this->aia, $thaiLife] as $company) {
            $journey = $resolver->resolveForProduct($product, $company->id);

            $this->assertNotNull($journey, "บริษัท {$company->name} ต้อง resolve เส้นทางกลางได้");
            $this->assertNull($journey->company_id);
            $this->assertTrue($resolver->paymentReachableFromEntry($journey));
        }
    }

    // ── What a shared product may point at ───────────────────────────

    public function test_a_super_admin_can_give_a_shared_product_a_platform_journey(): void
    {
        $product = $this->sharedProduct();
        $journey = $this->platformJourney(PipelineTemplate::KEY_DIRECT_SALE_DEFAULT);

        $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/products/{$product->id}", ['pipeline_template_id' => $journey->id])
            ->assertOk();

        $this->assertSame($journey->id, $product->refresh()->pipeline_template_id);
    }

    public function test_a_shared_product_may_not_carry_one_companys_journey(): void
    {
        /*
         * BR-6 in spirit: a shared product pointing at AIA's journey would
         * be AIA deciding the customer journey for every other company that
         * sells it. This is the same narrowing the brand and category rules
         * already apply — the form offers platform rows only, and the server
         * refuses anything else rather than trusting the form.
         */
        $product = $this->sharedProduct();
        $theirs = $this->companyJourney($this->aia, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT);

        $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/products/{$product->id}", ['pipeline_template_id' => $theirs->id])
            ->assertJsonValidationErrors('pipeline_template_id');
    }

    public function test_a_companys_own_product_may_use_either(): void
    {
        // Nothing taken away. A company product keeps its own journeys and
        // gains the platform ones as extra options.
        $product = Product::factory()->create(['company_id' => $this->aia->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        foreach ([
            $this->companyJourney($this->aia, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT),
            $this->platformJourney(PipelineTemplate::KEY_DIRECT_SALE_DEFAULT),
        ] as $journey) {
            $this->actingAs($admin)
                ->putJson("/api/v1/products/{$product->id}", ['pipeline_template_id' => $journey->id])
                ->assertOk();

            $this->assertSame($journey->id, $product->refresh()->pipeline_template_id);
        }
    }

    public function test_a_company_product_still_cannot_borrow_another_tenants_journey(): void
    {
        // The guarantee the old exact-match rule existed for. Widening it to
        // "own OR platform" must not have widened it to "anyone's".
        $thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        $product = Product::factory()->create(['company_id' => $this->aia->id]);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]))
            ->putJson("/api/v1/products/{$product->id}", [
                'pipeline_template_id' => $this->companyJourney($thaiLife, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT)->id,
            ])
            ->assertJsonValidationErrors('pipeline_template_id');
    }

    // ── Promotion carries the journey ────────────────────────────────

    public function test_promoting_a_product_carries_its_journey_to_the_platform(): void
    {
        /*
         * The regression itself. Before this change the promoted row came
         * out with a null journey; now it comes out pointing at the platform
         * twin of the journey it had a second earlier.
         */
        $product = Product::factory()->create([
            'company_id' => $this->aia->id,
            'pipeline_template_id' => $this->companyJourney($this->aia, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT)->id,
        ]);

        $this->artisan("catalog:promote-products --product={$product->id}")->assertSuccessful();

        $product = Product::withoutGlobalScope(SharedOrTenantScope::class)->findOrFail($product->id);
        $journey = PipelineTemplate::withoutGlobalScopes()->findOrFail($product->pipeline_template_id);

        $this->assertNull($product->company_id, 'สินค้าต้องกลายเป็นสินค้ากลาง');
        $this->assertNull($journey->company_id, 'เส้นทางที่ติดไปต้องเป็นเส้นทางกลาง');
        $this->assertSame(PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, $journey->key);
    }

    public function test_the_buy_button_survives_promotion(): void
    {
        /*
         * Said in the terms the person reported it in: a customer holding a
         * share link could buy this product, the product was promoted, and
         * the customer must still be able to buy it.
         */
        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);
        $this->certify($agent);

        $product = Product::factory()->create([
            'company_id' => $this->aia->id,
            'price_satang' => 890000,
            'pipeline_template_id' => $this->companyJourney($this->aia, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT)->id,
        ]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $this->aia->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);

        $this->getJson("/api/v1/public/product-shares/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.product.can_checkout', true);

        $this->artisan("catalog:promote-products --product={$product->id}")->assertSuccessful();

        $this->getJson("/api/v1/public/product-shares/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.product.can_checkout', true);
    }

    public function test_a_medical_journey_is_carried_across_unchanged_too(): void
    {
        /*
         * The rule is "the journey travels", not "everything becomes Direct
         * Sale". A product that genuinely requires a doctor's visit must
         * still require one after promotion — turning THAT into a buy button
         * would be the same class of silent error in the other direction.
         */
        $product = Product::factory()->create([
            'company_id' => $this->aia->id,
            'pipeline_template_id' => $this->companyJourney($this->aia, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT)->id,
        ]);

        $this->artisan("catalog:promote-products --product={$product->id}")->assertSuccessful();

        $product = Product::withoutGlobalScope(SharedOrTenantScope::class)->findOrFail($product->id);
        $journey = PipelineTemplate::withoutGlobalScopes()->findOrFail($product->pipeline_template_id);

        $this->assertNull($journey->company_id);
        $this->assertSame(PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT, $journey->key);
        $this->assertFalse(app(PipelineTemplateResolver::class)->paymentReachableFromEntry($journey));
    }

    public function test_a_companys_custom_journey_is_copied_rather_than_shared(): void
    {
        /*
         * A journey an admin authored themselves has no platform twin, so
         * one is made. It must be a COPY: pointing the shared product at
         * AIA's own row would hand AIA the journey for every other company,
         * and deleting that company would then take the journey with it.
         */
        $custom = $this->companyJourney($this->aia, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT);
        $custom->forceFill(['key' => 'aia_fast_track', 'name' => 'AIA Fast Track'])->save();

        $product = Product::factory()->create([
            'company_id' => $this->aia->id,
            'pipeline_template_id' => $custom->id,
        ]);

        $this->artisan("catalog:promote-products --product={$product->id}")->assertSuccessful();

        $product = Product::withoutGlobalScope(SharedOrTenantScope::class)->findOrFail($product->id);
        $journey = PipelineTemplate::withoutGlobalScopes()->findOrFail($product->pipeline_template_id);

        $this->assertNotSame($custom->id, $journey->id, 'ต้องเป็นสำเนา ไม่ใช่แถวเดิมของบริษัท');
        $this->assertNull($journey->company_id);
        $this->assertSame('AIA Fast Track', $journey->name);
        $this->assertSame(
            array_map(fn ($stage) => $stage->value, $custom->stageSequence()),
            array_map(fn ($stage) => $stage->value, $journey->stageSequence()),
        );
    }

    // ── Every product says which journey it sells under ──────────────

    public function test_a_product_created_before_this_release_now_names_a_journey(): void
    {
        /*
         * 2026-09-09 (human: "ทำกับตัวสินค้าครับ ไม่ใช่หมวด และหากของเก่า
         * ปรับเป็น Direct sale").
         *
         * A NULL journey never meant "no journey" — it meant "inherit", and
         * the end of that chain is the Medical Package fail-safe, whose
         * first step is an appointment. So every product nobody had thought
         * about was un-buyable from its own share link.
         *
         * The migration writes the answer down. This asserts the state it
         * leaves behind, on a row created exactly the way the old ones were:
         * with nothing chosen.
         */
        $company = Company::factory()->create(['name' => 'Fresh']);
        app(PipelineTemplateProvisioner::class)->provision($company);

        $product = Product::factory()->create([
            'company_id' => $company->id,
            'pipeline_template_id' => null,
        ]);

        // Re-running the repair is what a deploy does; it must be safe.
        $this->runDirectSaleRepair();

        $journey = PipelineTemplate::withoutGlobalScopes()->findOrFail($product->refresh()->pipeline_template_id);

        $this->assertSame($company->id, $journey->company_id, 'ต้องเป็นเส้นทางของบริษัทตัวเอง ไม่ใช่ของบริษัทอื่นหรือของกลาง');
        $this->assertSame(PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, $journey->key);
        $this->assertTrue(app(PipelineTemplateResolver::class)->paymentReachableFromEntry($journey));
    }

    public function test_the_repair_never_overrules_a_journey_somebody_chose(): void
    {
        /*
         * The line this must not cross. A product deliberately set to
         * Medical Package is a product that needs a doctor's visit before
         * payment; turning that into a buy button is the same class of
         * silent error as the one being fixed, pointing the other way.
         */
        $medical = $this->companyJourney($this->aia, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT);
        $product = Product::factory()->create([
            'company_id' => $this->aia->id,
            'pipeline_template_id' => $medical->id,
        ]);

        $this->runDirectSaleRepair();

        $this->assertSame($medical->id, $product->refresh()->pipeline_template_id);
    }

    public function test_the_repair_leaves_each_company_with_its_own_journey_row(): void
    {
        // BR-6. "Direct Sale" is a different row per company, so a single
        // global id would point every tenant at one company's journey.
        $thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        app(PipelineTemplateProvisioner::class)->provision($this->aia);
        app(PipelineTemplateProvisioner::class)->provision($thaiLife);

        $mine = Product::factory()->create(['company_id' => $this->aia->id, 'pipeline_template_id' => null]);
        $theirs = Product::factory()->create(['company_id' => $thaiLife->id, 'pipeline_template_id' => null]);

        $this->runDirectSaleRepair();

        $this->assertSame(
            $this->aia->id,
            PipelineTemplate::withoutGlobalScopes()->findOrFail($mine->refresh()->pipeline_template_id)->company_id,
        );
        $this->assertSame(
            $thaiLife->id,
            PipelineTemplate::withoutGlobalScopes()->findOrFail($theirs->refresh()->pipeline_template_id)->company_id,
        );
    }

    /**
     * The migration's own work, replayed.
     *
     * RefreshDatabase runs every migration before any of these rows exist,
     * so the repair has nothing to find at that point — the products under
     * test are created afterwards. Calling the migration object directly
     * runs exactly the code that ships, rather than a second copy of its
     * logic that could drift from it.
     */
    private function runDirectSaleRepair(): void
    {
        (require database_path('migrations/2026_09_09_120000_set_direct_sale_on_products_with_no_journey.php'))->up();
    }

    private function certify(User $agent): void
    {
        // BR-1 — an uncertified agent's link refuses checkout for a reason
        // that has nothing to do with journeys, which would make the test
        // above pass or fail for the wrong cause.
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        UserCertification::create([
            'company_id' => $this->aia->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }
}
