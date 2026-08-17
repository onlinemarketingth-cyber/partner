<?php

namespace Tests\Feature\Referral;

use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-136 deliverable 2 — ReferralResource exposes the referral's OWN
 * ordered stage sequence plus its next legal stage.
 *
 * WHY: both Kanban boards still render CLAUDE.md §4.3's five hardcoded
 * columns (frontend/src/views/PipelineView.vue:67,
 * frontend-admin/src/views/ReferralPipelineManagementView.vue:40). After
 * ADR-026 a short-journey referral lands on a five-column board and
 * dragging it to a stage its template does not contain 422s. ag-ui needs
 * the real sequence per referral, from the backend (§3 — business logic
 * never in a Vue component).
 */
class ReferralPipelineSequenceTest extends TestCase
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
    private function makeCompanyAgentProduct(): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        return [$company, $agent, $product];
    }

    private function makeReferral(
        Company $company,
        User $agent,
        Product $product,
        ?PipelineTemplate $template,
        PipelineStage $stage = PipelineStage::CompleteRegistered,
    ): Referral {
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        return Referral::create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'current_stage' => $stage,
            'meeting_number' => null,
            'submitted_at' => now(),
            'pipeline_template_id' => $template?->id,
        ]);
    }

    public function test_a_direct_sale_referral_reports_only_its_own_two_stages(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->makeTemplate($company, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
        $referral = $this->makeReferral($company, $agent, $product, $template);

        $this->actingAs($agent)
            ->getJson("/api/v1/referrals/{$referral->id}")
            ->assertOk()
            ->assertJsonPath('data.pipeline.stages', [
                ['key' => 'complete_registered', 'label' => 'Complete Registered'],
                ['key' => 'complete_payment', 'label' => 'Complete Payment'],
            ])
            ->assertJsonPath('data.pipeline.next_stage.key', 'complete_payment');
    }

    public function test_a_medical_referral_reports_the_full_five_stage_journey_in_order(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->makeTemplate($company, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
        ]);
        $referral = $this->makeReferral($company, $agent, $product, $template);

        $response = $this->actingAs($agent)
            ->getJson("/api/v1/referrals/{$referral->id}")
            ->assertOk()
            ->assertJsonPath('data.pipeline.next_stage.key', 'waiting_appointment');

        // Order is the journey — asserted as a sequence, not a set.
        $this->assertSame([
            'complete_registered',
            'waiting_appointment',
            'finish_1st_doctor_meeting',
            'complete_payment',
            'ongoing_next_meeting',
        ], array_column($response->json('data.pipeline.stages'), 'key'));
    }

    public function test_a_legacy_referral_with_no_template_falls_back_to_the_default_sequence(): void
    {
        // ADR-026 §3.6 — pipeline_template_id IS NULL on every pre-ADR-026
        // row; the board must look exactly as it does today for them.
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $referral = $this->makeReferral($company, $agent, $product, null);

        $response = $this->actingAs($agent)
            ->getJson("/api/v1/referrals/{$referral->id}")
            ->assertOk()
            ->assertJsonPath('data.pipeline_template_id', null)
            ->assertJsonPath('data.pipeline.next_stage.key', 'waiting_appointment');

        $this->assertSame(
            array_map(fn (PipelineStage $stage) => $stage->value, PipelineStage::defaultSequence()),
            array_column($response->json('data.pipeline.stages'), 'key'),
        );
    }

    public function test_ongoing_next_meeting_reports_itself_as_the_next_stage(): void
    {
        // The self-loop is a property of the STAGE (ADR-026 §3.6) — a board
        // must still offer "advance" there, and must not derive that rule
        // client-side.
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->makeTemplate($company, 'medical', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
        ]);
        $referral = $this->makeReferral($company, $agent, $product, $template, PipelineStage::OngoingNextMeeting);

        $this->actingAs($agent)
            ->getJson("/api/v1/referrals/{$referral->id}")
            ->assertOk()
            ->assertJsonPath('data.pipeline.next_stage.key', 'ongoing_next_meeting');
    }

    public function test_a_referral_at_the_final_stage_reports_no_next_stage(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->makeTemplate($company, 'ends_in_follow_up', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
            PipelineStage::FollowUp,
        ]);
        $referral = $this->makeReferral($company, $agent, $product, $template, PipelineStage::FollowUp);

        $this->actingAs($agent)
            ->getJson("/api/v1/referrals/{$referral->id}")
            ->assertOk()
            ->assertJsonPath('data.pipeline.next_stage', null)
            ->assertJsonCount(3, 'data.pipeline.stages');
    }

    public function test_a_broken_journey_renders_an_empty_sequence_instead_of_failing_the_whole_list(): void
    {
        // Fail-closed WITHOUT taking the page down: advance() must keep
        // refusing (it does — PipelineTemplateAdvanceTest covers that),
        // but serialising a list of fifty referrals must not 422 because
        // one row's template was emptied by hand.
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->makeTemplate($company, 'to_be_emptied', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
        $referral = $this->makeReferral($company, $agent, $product, $template);

        PipelineTemplateStage::where('pipeline_template_id', $template->id)->delete();

        $this->actingAs($agent)
            ->getJson("/api/v1/referrals/{$referral->id}")
            ->assertOk()
            ->assertJsonPath('data.pipeline.stages', [])
            ->assertJsonPath('data.pipeline.next_stage', null);
    }

    public function test_the_pipeline_block_leaks_no_tenant_or_config_identifiers(): void
    {
        // §5/§6 — a board needs the stage vocabulary, not company_id and
        // not the primary keys of config rows.
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->makeTemplate($company, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
        $referral = $this->makeReferral($company, $agent, $product, $template);

        $response = $this->actingAs($agent)
            ->getJson("/api/v1/referrals/{$referral->id}")
            ->assertOk();

        $pipeline = $response->json('data.pipeline');
        $this->assertSame(['stages', 'next_stage'], array_keys($pipeline));

        foreach ($pipeline['stages'] as $stage) {
            $this->assertSame(['key', 'label'], array_keys($stage));
        }
    }

    public function test_two_referrals_on_different_journeys_report_different_boards_in_one_list(): void
    {
        // The reason this shape had to be per-row rather than per-request:
        // one agent's board can contain both journeys at once.
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();

        $short = $this->makeTemplate($company, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
        $long = $this->makeTemplate($company, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
        ]);

        $this->makeReferral($company, $agent, $product, $short);
        $this->makeReferral($company, $agent, $product, $long);

        $response = $this->actingAs($agent)->getJson('/api/v1/referrals')->assertOk();

        $counts = array_map(
            fn (array $row) => count($row['pipeline']['stages']),
            $response->json('data'),
        );
        sort($counts);

        $this->assertSame([2, 5], $counts);
    }
}
