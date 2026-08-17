<?php

namespace Tests\Feature\Referral;

use App\Enums\PipelineStage;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Pipeline\PipelineTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ADR-026 §3.3 (TASK-132) — one test per level of the most-specific-wins
// chain: product -> category -> company -> seeded medical_package_default.
class PipelineTemplateResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<PipelineStage>  $stages
     */
    private function makeTemplate(Company $company, string $key, array $stages, bool $isSystem = false): PipelineTemplate
    {
        $template = PipelineTemplate::create([
            'company_id' => $company->id,
            'key' => $key,
            'name' => ucfirst(str_replace('_', ' ', $key)),
            'is_system' => $isSystem,
        ]);

        foreach ($stages as $position => $stage) {
            PipelineTemplateStage::create([
                'company_id' => $company->id,
                'pipeline_template_id' => $template->id,
                'stage' => $stage,
                'position' => $position,
            ]);
        }

        return $template;
    }

    /** The seeded fail-safe every company is supposed to have (ADR-026 §3.1). */
    private function makeSystemMedicalDefault(Company $company): PipelineTemplate
    {
        return $this->makeTemplate($company, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
        ], isSystem: true);
    }

    private function makeShortTemplate(Company $company, string $key = 'short'): PipelineTemplate
    {
        return $this->makeTemplate($company, $key, [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
    }

    private function resolver(): PipelineTemplateResolver
    {
        return app(PipelineTemplateResolver::class);
    }

    public function test_the_products_own_template_wins_over_every_other_scope(): void
    {
        $company = Company::factory()->create();
        $this->makeSystemMedicalDefault($company);

        $productTemplate = $this->makeShortTemplate($company, 'product_level');
        $categoryTemplate = $this->makeShortTemplate($company, 'category_level');
        $companyTemplate = $this->makeShortTemplate($company, 'company_level');

        $company->update(['default_pipeline_template_id' => $companyTemplate->id]);
        $category = ProductCategory::factory()->create([
            'company_id' => $company->id,
            'pipeline_template_id' => $categoryTemplate->id,
        ]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'pipeline_template_id' => $productTemplate->id,
        ]);

        $this->assertSame($productTemplate->id, $this->resolver()->resolveForProduct($product)?->id);
    }

    public function test_falls_through_to_the_category_template_when_the_product_has_none(): void
    {
        $company = Company::factory()->create();
        $this->makeSystemMedicalDefault($company);

        $categoryTemplate = $this->makeShortTemplate($company, 'category_level');
        $companyTemplate = $this->makeShortTemplate($company, 'company_level');

        $company->update(['default_pipeline_template_id' => $companyTemplate->id]);
        $category = ProductCategory::factory()->create([
            'company_id' => $company->id,
            'pipeline_template_id' => $categoryTemplate->id,
        ]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'pipeline_template_id' => null,
        ]);

        $this->assertSame($categoryTemplate->id, $this->resolver()->resolveForProduct($product)?->id);
    }

    public function test_falls_through_to_the_company_default_when_product_and_category_have_none(): void
    {
        $company = Company::factory()->create();
        $this->makeSystemMedicalDefault($company);

        $companyTemplate = $this->makeShortTemplate($company, 'company_level');
        $company->update(['default_pipeline_template_id' => $companyTemplate->id]);

        $category = ProductCategory::factory()->create(['company_id' => $company->id, 'pipeline_template_id' => null]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'pipeline_template_id' => null,
        ]);

        $this->assertSame($companyTemplate->id, $this->resolver()->resolveForProduct($product)?->id);
    }

    public function test_falls_back_to_the_seeded_medical_package_default_when_nothing_is_scoped(): void
    {
        $company = Company::factory()->create();
        $systemDefault = $this->makeSystemMedicalDefault($company);

        $category = ProductCategory::factory()->create(['company_id' => $company->id, 'pipeline_template_id' => null]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'pipeline_template_id' => null,
        ]);

        $resolved = $this->resolver()->resolveForProduct($product);

        $this->assertSame($systemDefault->id, $resolved?->id);
        // ADR-026 §3.1 — the fail-safe is CLAUDE.md §4.3's five stages, verbatim and in order.
        $this->assertSame([
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
        ], $resolved->stageSequence());
    }

    public function test_fails_closed_when_not_even_the_system_default_exists(): void
    {
        // ADR-026 §3.3 calls medical_package_default "never null in
        // practice" — but that is a seeded-data assumption, not a schema
        // invariant, so the resolver must return null rather than invent
        // a journey (which TASK-133 then treats as the legacy enum path).
        $company = Company::factory()->create();
        $product = Product::factory()->create(['company_id' => $company->id, 'pipeline_template_id' => null]);

        $this->assertNull($this->resolver()->resolveForProduct($product));
    }

    public function test_a_template_belonging_to_another_company_is_never_resolved(): void
    {
        // BR-6 / ADR-026 §4 — "a product may not point at another
        // company's template (validated, not assumed)". This writes the
        // cross-tenant id directly to the DB, bypassing the Form Request
        // entirely, to prove the RESOLVER itself refuses to follow it
        // rather than relying on validation having been the only door.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $ownSystemDefault = $this->makeSystemMedicalDefault($companyA);
        $foreignTemplate = $this->makeShortTemplate($companyB, 'company_b_template');

        $product = Product::factory()->create(['company_id' => $companyA->id]);
        DB::table('products')->where('id', $product->id)->update(['pipeline_template_id' => $foreignTemplate->id]);

        $resolved = $this->resolver()->resolveForProduct($product->fresh());

        $this->assertNotSame($foreignTemplate->id, $resolved?->id);
        $this->assertSame($ownSystemDefault->id, $resolved?->id);
    }

    public function test_updating_a_product_with_another_companys_template_is_rejected(): void
    {
        // The Form Request half of the same rule → 422, not a silent
        // fall-through (TASK-132 acceptance criteria).
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $foreignTemplate = $this->makeShortTemplate($companyB, 'company_b_template');

        $admin = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $product = Product::factory()->create(['company_id' => $companyA->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$product->id}", ['pipeline_template_id' => $foreignTemplate->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pipeline_template_id');
    }

    public function test_updating_a_product_with_its_own_companys_template_is_accepted(): void
    {
        $company = Company::factory()->create();
        $template = $this->makeShortTemplate($company);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$product->id}", ['pipeline_template_id' => $template->id])
            ->assertOk();

        $this->assertSame($template->id, $product->fresh()->pipeline_template_id);
    }

    public function test_an_agent_of_another_company_cannot_read_a_foreign_template_through_the_scope(): void
    {
        // BR-6 / §5 rule 5 — TenantScope on PipelineTemplate itself.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $foreignTemplate = $this->makeShortTemplate($companyB, 'company_b_template');

        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $this->actingAs($agentA);

        $this->assertNull(PipelineTemplate::find($foreignTemplate->id));
    }
}
