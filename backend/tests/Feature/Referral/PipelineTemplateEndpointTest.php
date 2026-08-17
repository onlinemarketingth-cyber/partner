<?php

namespace Tests\Feature\Referral;

use App\Enums\PipelineStage;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-136 deliverable 3 — GET /api/v1/pipeline-templates (read-only) and
 * ProductResource::effective_pipeline_template.
 *
 * Before this there was no controller, resource, policy or route for
 * pipeline templates at all, which made the whole ADR-026 feature inert:
 * TASK-134a's migration put every existing product on `direct_sale_default`
 * and no screen could change it or even show what a product inherits.
 */
class PipelineTemplateEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<PipelineStage>  $stages
     */
    private function makeTemplate(Company $company, string $key, array $stages, bool $isSystem = true): PipelineTemplate
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

    private function directSale(Company $company): PipelineTemplate
    {
        return $this->makeTemplate($company, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
    }

    private function medical(Company $company): PipelineTemplate
    {
        return $this->makeTemplate($company, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
        ]);
    }

    // ── The list endpoint ──────────────────────────────────────────────

    public function test_a_company_admin_lists_their_own_templates_with_ordered_stages(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->directSale($company);

        $response = $this->actingAs($admin)->getJson('/api/v1/pipeline-templates')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, $response->json('data.0.key'));
        $this->assertTrue($response->json('data.0.is_system'));
        // The stage list is the point of the endpoint — a chooser that
        // only showed names would ask an admin to pick a journey without
        // showing them the journey.
        $this->assertSame(
            ['complete_registered', 'complete_payment'],
            array_column($response->json('data.0.stages'), 'key'),
        );
    }

    public function test_a_company_admin_never_sees_another_companys_templates(): void
    {
        // BR-6 — TenantScope, asserted rather than assumed.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->directSale($companyA);
        $this->medical($companyB);

        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $response = $this->actingAs($adminA)->getJson('/api/v1/pipeline-templates')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($companyA->id, $response->json('data.0.company_id'));
    }

    public function test_a_super_admin_sees_templates_across_companies(): void
    {
        // §5 rule 4 — the one role that legitimately crosses the boundary.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->directSale($companyA);
        $this->medical($companyB);

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/pipeline-templates')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_an_agent_may_not_list_pipeline_templates(): void
    {
        // Deliberately narrower than BrandPolicy/ClientCategoryPolicy: an
        // Agent needs the journey of the referral in front of them (exposed
        // per-row on ReferralResource), not the company's config catalogue.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->directSale($company);

        $this->actingAs($agent)
            ->getJson('/api/v1/pipeline-templates')
            ->assertForbidden();
    }

    public function test_a_guest_may_not_list_pipeline_templates(): void
    {
        $this->getJson('/api/v1/pipeline-templates')->assertUnauthorized();
    }

    public function test_there_is_no_write_route_for_pipeline_templates(): void
    {
        // Authoring is TASK-134b and must not exist until the ADR-026 §3.5
        // invariants are wired into a Form Request — a template saved
        // without complete_payment is a silent BR-4 commission outage.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $template = $this->directSale($company);

        // 405 for POST: the collection URI exists, but only for GET.
        $this->actingAs($admin)->postJson('/api/v1/pipeline-templates', [])->assertStatus(405);
        // 404 for the member URI: ->only(['index']) never registers
        // /pipeline-templates/{id} for ANY verb, so there is no route to
        // report a method mismatch against.
        $this->actingAs($admin)->putJson("/api/v1/pipeline-templates/{$template->id}", [])->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/v1/pipeline-templates/{$template->id}")->assertNotFound();
    }

    // ── effective_pipeline_template on ProductResource ─────────────────

    public function test_a_products_own_template_override_wins(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $medical = $this->medical($company);
        $direct = $this->directSale($company);
        $company->update(['default_pipeline_template_id' => $medical->id]);

        $product = Product::factory()->create([
            'company_id' => $company->id,
            'pipeline_template_id' => $direct->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.pipeline_template_id', $direct->id)
            ->assertJsonPath('data.effective_pipeline_template.id', $direct->id)
            ->assertJsonPath('data.effective_pipeline_template.key', PipelineTemplate::KEY_DIRECT_SALE_DEFAULT);
    }

    public function test_a_product_with_no_override_reports_what_it_inherits_from_its_category(): void
    {
        // This is the field's whole reason to exist: the admin form cannot
        // offer an honest "inherit" option without saying what inherit
        // currently MEANS (ADR-026 §3.3 resolution chain).
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $medical = $this->medical($company);
        $direct = $this->directSale($company);
        $company->update(['default_pipeline_template_id' => $medical->id]);

        $category = ProductCategory::factory()->create([
            'company_id' => $company->id,
            'pipeline_template_id' => $direct->id,
        ]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'pipeline_template_id' => null,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.pipeline_template_id', null)
            ->assertJsonPath('data.effective_pipeline_template.key', PipelineTemplate::KEY_DIRECT_SALE_DEFAULT);
    }

    public function test_a_product_with_no_override_and_no_category_scope_falls_through_to_the_company_default(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $medical = $this->medical($company);
        $this->directSale($company);
        $company->update(['default_pipeline_template_id' => $medical->id]);

        $category = ProductCategory::factory()->create([
            'company_id' => $company->id,
            'pipeline_template_id' => null,
        ]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'pipeline_template_id' => null,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.effective_pipeline_template.key', PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT);
    }

    public function test_effective_template_is_null_when_the_company_has_no_templates_at_all(): void
    {
        // The resolver fails closed rather than inventing a journey
        // (ADR-026 §3.3) — ag-ui must read null as "misconfigured", not
        // as "no journey".
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'pipeline_template_id' => null,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.effective_pipeline_template', null);
    }

    public function test_a_product_never_resolves_another_companys_template(): void
    {
        // BR-6 — a dangling/cross-tenant reference is treated as unset and
        // resolution falls through, never followed.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $ownTemplate = $this->directSale($companyA);
        $foreignTemplate = $this->medical($companyB);
        $companyA->update(['default_pipeline_template_id' => $ownTemplate->id]);

        // Hand-written cross-tenant pointer, bypassing the Form Request.
        $product = Product::factory()->create(['company_id' => $companyA->id]);
        $product->forceFill(['pipeline_template_id' => $foreignTemplate->id])->save();

        $this->actingAs($admin)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.effective_pipeline_template.id', $ownTemplate->id);
    }
}
