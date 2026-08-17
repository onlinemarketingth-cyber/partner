<?php

namespace Tests\Feature\Referral;

use App\Models\Brand;
use App\Models\Client;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TASK-134a — the data backfill of ADR-026 §3.8.
 *
 * The TASK-132 spec calls the two DIFFERENT defaults "the least obvious
 * line in the sprint" and requires them to be asserted with fixtures of
 * both kinds, which is what this file is for:
 *
 *   products  -> direct_sale_default      ("สินค้าเดิมไม่ต้องพบแพทย์")
 *   referrals -> medical_package_default  (the journey already in flight)
 *
 * The migration itself is exercised, not a copy of its logic: it runs
 * once (as a no-op) against the empty test database during
 * RefreshDatabase, so each test builds its fixtures and then invokes
 * up()/down() on a freshly required instance of the migration file.
 */
class PipelineTemplateBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_08_22_090000_backfill_pipeline_templates_on_products_and_referrals.php';

    /**
     * `require` (not require_once) re-executes the file, so every call
     * returns a fresh instance of the anonymous migration class.
     */
    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /**
     * Stand-in for what PipelineTemplateSeeder produces. Only the
     * (company_id, key) pair matters to the backfill — it never reads
     * pipeline_template_stages — so the stage rows are deliberately
     * omitted to keep the fixture honest about what is under test.
     *
     * @return array{0: PipelineTemplate, 1: PipelineTemplate} [medical, directSale]
     */
    private function seedSystemTemplates(Company $company): array
    {
        return [
            PipelineTemplate::factory()->create([
                'company_id' => $company->id,
                'key' => PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT,
                'is_system' => true,
            ]),
            PipelineTemplate::factory()->create([
                'company_id' => $company->id,
                'key' => PipelineTemplate::KEY_DIRECT_SALE_DEFAULT,
                'is_system' => true,
            ]),
        ];
    }

    private function makeProduct(Company $company): Product
    {
        return Product::factory()->create([
            'company_id' => $company->id,
            'brand_id' => Brand::factory()->create(['company_id' => $company->id])->id,
            'category_id' => ProductCategory::factory()->create(['company_id' => $company->id])->id,
        ]);
    }

    private function makeReferral(Company $company, Product $product, ?int $templateId = null): Referral
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
        ]);

        return Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'pipeline_template_id' => $templateId,
        ]);
    }

    public function test_products_get_direct_sale_and_referrals_get_medical_package(): void
    {
        $company = Company::factory()->create();
        [$medical, $directSale] = $this->seedSystemTemplates($company);

        $product = $this->makeProduct($company);
        $referral = $this->makeReferral($company, $product);

        $this->assertNull($product->pipeline_template_id);
        $this->assertNull($referral->pipeline_template_id);

        $this->migration()->up();

        // The human's decision (ADR-026 §3.8): existing products do not
        // require a doctor meeting.
        $this->assertSame($directSale->id, $product->fresh()->pipeline_template_id);

        // ...but the referral already walking the medical journey keeps
        // a template that CONTAINS the stage it may be parked at
        // (§3.4). Giving it the product's short template would strand
        // it. This assertion is the whole point of the migration.
        $this->assertSame($medical->id, $referral->fresh()->pipeline_template_id);
        $this->assertNotSame($directSale->id, $referral->fresh()->pipeline_template_id);
    }

    public function test_a_referral_already_stamped_at_creation_is_not_overwritten(): void
    {
        $company = Company::factory()->create();
        [$medical] = $this->seedSystemTemplates($company);

        $custom = PipelineTemplate::factory()->create(['company_id' => $company->id]);
        $product = $this->makeProduct($company);
        $alreadyStamped = $this->makeReferral($company, $product, $custom->id);

        $this->migration()->up();

        // ADR-026 §3.4 — the creation-time snapshot is authoritative and
        // immutable (same reasoning as BR-4's ledger). The backfill only
        // ever fills NULLs.
        $this->assertSame($custom->id, $alreadyStamped->fresh()->pipeline_template_id);
        $this->assertNotSame($medical->id, $alreadyStamped->fresh()->pipeline_template_id);
    }

    public function test_a_product_already_pointing_at_a_template_is_not_overwritten(): void
    {
        $company = Company::factory()->create();
        $this->seedSystemTemplates($company);

        $custom = PipelineTemplate::factory()->create(['company_id' => $company->id]);
        $product = $this->makeProduct($company);
        $product->update(['pipeline_template_id' => $custom->id]);

        $this->migration()->up();

        $this->assertSame($custom->id, $product->fresh()->pipeline_template_id);
    }

    public function test_a_company_without_seeded_templates_is_skipped_and_warned_never_borrowing_another_companys_template(): void
    {
        // BR-6, highest priority: the failure mode this guards against is
        // handing company B a template id that belongs to company A —
        // a cross-tenant write that would be invisible afterwards
        // because a template id looks like any other integer.
        $companyA = Company::factory()->create();
        [$medicalA, $directSaleA] = $this->seedSystemTemplates($companyA);

        $companyB = Company::factory()->create(); // no templates seeded

        $productA = $this->makeProduct($companyA);
        $productB = $this->makeProduct($companyB);
        $referralB = $this->makeReferral($companyB, $productB);

        Log::spy();

        $this->migration()->up();

        // Company B is untouched — NULL, not company A's ids.
        $this->assertNull($productB->fresh()->pipeline_template_id);
        $this->assertNull($referralB->fresh()->pipeline_template_id);

        // ...and company A is still backfilled: one bad tenant must not
        // abort the migration for everybody else.
        $this->assertSame($directSaleA->id, $productA->fresh()->pipeline_template_id);
        $this->assertNotSame($medicalA->id, $productB->fresh()->pipeline_template_id);

        // Skipped LOUDLY, never silently.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, "company {$companyB->id} has no seeded pipeline templates")
        );
    }

    public function test_down_reverts_the_backfilled_columns_to_null(): void
    {
        $company = Company::factory()->create();
        $this->seedSystemTemplates($company);

        $custom = PipelineTemplate::factory()->create(['company_id' => $company->id]);
        $product = $this->makeProduct($company);
        $referral = $this->makeReferral($company, $product);
        $customProduct = $this->makeProduct($company);
        $customProduct->update(['pipeline_template_id' => $custom->id]);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertNull($product->fresh()->pipeline_template_id);
        $this->assertNull($referral->fresh()->pipeline_template_id);

        // down() is scoped to the two SYSTEM template ids: an
        // admin-authored assignment this migration never wrote must
        // survive the rollback — nulling it would be data loss dressed
        // up as a reversal.
        $this->assertSame($custom->id, $customProduct->fresh()->pipeline_template_id);
    }

    public function test_it_is_safe_to_run_on_an_empty_database(): void
    {
        // Fresh install: `php artisan migrate` runs before `db:seed`, so
        // there are no companies, products, referrals or templates yet.
        $this->assertSame(0, DB::table('companies')->count());

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('referrals')->count());
    }
}
