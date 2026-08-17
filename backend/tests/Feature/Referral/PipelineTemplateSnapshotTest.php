<?php

namespace Tests\Feature\Referral;

use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Referral\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-026 §3.4 (TASK-132) — referrals.pipeline_template_id is stamped
 * ONCE at creation and never re-resolved. Same reasoning as BR-4's
 * immutable ledger: an admin editing a template must not reroute — or
 * strand — a customer already halfway through a journey.
 */
class PipelineTemplateSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<PipelineStage>  $stages
     */
    private function makeTemplate(Company $company, string $key, array $stages): PipelineTemplate
    {
        $template = PipelineTemplate::create([
            'company_id' => $company->id,
            'key' => $key,
            'name' => ucfirst(str_replace('_', ' ', $key)),
            'is_system' => false,
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

    /**
     * @return array{0: Company, 1: User, 2: Product}
     */
    private function makeCertifiedAgentAndProduct(): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // BR-1 (Access Gate) — ReferralService::create() refuses without it.
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        $category = ProductCategory::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'category_id' => $category->id]);

        return [$company, $agent, $product];
    }

    private function createReferral(Company $company, User $agent, Product $product)
    {
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        return app(ReferralService::class)->create([
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
        ], $agent);
    }

    public function test_a_referral_is_stamped_with_the_resolved_template_at_creation(): void
    {
        [$company, $agent, $product] = $this->makeCertifiedAgentAndProduct();

        $template = $this->makeTemplate($company, 'direct_sale', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
        $product->update(['pipeline_template_id' => $template->id]);

        $referral = $this->createReferral($company, $agent, $product);

        $this->assertSame($template->id, $referral->pipeline_template_id);
    }

    public function test_editing_the_template_afterwards_does_not_rewrite_the_referrals_snapshot(): void
    {
        [$company, $agent, $product] = $this->makeCertifiedAgentAndProduct();

        $original = $this->makeTemplate($company, 'original_journey', [
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::CompletePayment,
        ]);
        $product->update(['pipeline_template_id' => $original->id]);

        $referral = $this->createReferral($company, $agent, $product);
        $this->assertSame($original->id, $referral->pipeline_template_id);

        // 1. Rename the template and strip a stage out of the middle of
        //    it — the exact edit ADR-026 §3.4 warns about (a referral
        //    parked at waiting_appointment would otherwise be left with
        //    no legal next stage and no legal previous one).
        $original->update(['name' => 'Renamed Journey']);
        PipelineTemplateStage::where('pipeline_template_id', $original->id)
            ->where('stage', PipelineStage::WaitingAppointment->value)
            ->delete();

        // 2. Repoint the PRODUCT at a completely different template.
        $replacement = $this->makeTemplate($company, 'replacement_journey', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
            PipelineStage::Delivery,
        ]);
        $product->update(['pipeline_template_id' => $replacement->id]);

        // The referral still points at the journey it was created under.
        $this->assertSame($original->id, $referral->fresh()->pipeline_template_id);
        $this->assertNotSame($replacement->id, $referral->fresh()->pipeline_template_id);
        $this->assertDatabaseHas('referrals', [
            'id' => $referral->id,
            'pipeline_template_id' => $original->id,
        ]);
    }

    public function test_the_snapshot_is_null_when_no_template_resolves(): void
    {
        // Fail-closed, not fail-guessed: with no scoped template and no
        // seeded medical_package_default, the referral is created with a
        // NULL snapshot, which TASK-133 reads as "legacy, use the enum's
        // default edges" (ADR-026 §3.6).
        [$company, $agent, $product] = $this->makeCertifiedAgentAndProduct();

        $referral = $this->createReferral($company, $agent, $product);

        $this->assertNull($referral->pipeline_template_id);
        $this->assertSame(PipelineStage::CompleteRegistered, $referral->current_stage);
    }
}
