<?php

namespace Tests\Feature\Referral;

use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionSplitSetting;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-026 (ADR-006) — co_agent_id/split_percentage validation, both at
// referral creation (StoreReferralRequest/ReferralService::create()) and
// via the dedicated PATCH /referrals/{referral}/co-agent endpoint
// (ReferralService::setCoAgent()). BR-6: cross-tenant co_agent_id must
// never be accepted.
class SetCoAgentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TASK-174 — the co-agent split is a per-company switch and it ships OFF
     * (D2). Everything in this class is about the feature WHILE IT IS ON, so
     * every test turns it on for its own company first. The switched-OFF
     * behaviour of these same endpoints lives in
     * Tests\Feature\Commission\CommissionSplitSettingTest.
     */
    private function enableSplit(Company $company): void
    {
        CommissionSplitSetting::create(['company_id' => $company->id, 'is_enabled' => true]);
    }

    private function passBasicCert(User $agent, Company $company): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
    }

    private function advanceToStage(Referral $referral, User $agent, PipelineStage $target): Referral
    {
        while ($referral->current_stage !== $target) {
            $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
            $referral->refresh();
        }

        return $referral;
    }

    /**
     * TASK-170 — an explicit journey for this referral, so the cutoff is
     * asserted against a REAL template rather than the NULL-snapshot
     * fallback that happens to be the medical five (ADR-026 §3.6).
     *
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

    private function makeReferral(Company $company, User $agent, ?PipelineTemplate $template = null): Referral
    {
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        return Referral::create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'pipeline_template_id' => $template?->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);
    }

    public function test_co_agent_options_lists_other_agents_in_the_same_company_only(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $colleague = User::factory()->agent()->create(['company_id' => $company->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);

        $response = $this->actingAs($agent)->getJson('/api/v1/referrals/co-agent-options')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($colleague->id));
        $this->assertFalse($ids->contains($foreignAgent->id));
        $this->assertFalse($ids->contains($agent->id)); // never lists yourself
    }

    public function test_a_split_referral_can_be_submitted_at_creation_time(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 40,
        ])
            ->assertCreated()
            ->assertJsonPath('data.co_agent.id', $coAgent->id)
            ->assertJsonPath('data.split_percentage', 40);
    }

    public function test_a_co_agent_from_another_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'co_agent_id' => $foreignAgent->id,
            'split_percentage' => 40,
        ])->assertUnprocessable()->assertJsonValidationErrors('co_agent_id');
    }

    public function test_split_percentage_must_be_between_1_and_99(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id, 'product_id' => $product->id, 'branch' => 'Silom',
            'co_agent_id' => $coAgent->id, 'split_percentage' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('split_percentage');

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id, 'product_id' => $product->id, 'branch' => 'Silom',
            'co_agent_id' => $coAgent->id, 'split_percentage' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('split_percentage');
    }

    public function test_co_agent_id_and_split_percentage_must_both_be_present_or_both_absent(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id, 'product_id' => $product->id, 'branch' => 'Silom',
            'co_agent_id' => $coAgent->id, // split_percentage omitted
        ])->assertUnprocessable()->assertJsonValidationErrors('split_percentage');
    }

    public function test_co_agent_cannot_be_the_same_as_the_referring_agent(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id, 'product_id' => $product->id, 'branch' => 'Silom',
            'co_agent_id' => $agent->id, 'split_percentage' => 50,
        ])->assertUnprocessable()->assertJsonValidationErrors('co_agent_id');
    }

    public function test_co_agent_can_be_set_via_patch_before_complete_payment(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->actingAs($agent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 25,
        ])
            ->assertOk()
            ->assertJsonPath('data.co_agent.id', $coAgent->id)
            ->assertJsonPath('data.split_percentage', 25);
    }

    public function test_co_agent_cannot_be_changed_once_complete_payment_is_reached(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->actingAs($agent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 25,
        ])->assertUnprocessable()->assertJsonValidationErrors('co_agent_id');

        $this->assertDatabaseHas('referrals', ['id' => $referral->id, 'co_agent_id' => null]);
    }

    public function test_co_agent_can_be_cleared_by_sending_both_fields_null(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'co_agent_id' => $coAgent->id, 'split_percentage' => 30,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->actingAs($agent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => null,
            'split_percentage' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.co_agent', null)
            ->assertJsonPath('data.split_percentage', null);
    }

    // ---------------------------------------------------------------
    // TASK-170 — THE EDIT CUTOFF IS A POSITION ON THE REFERRAL'S OWN
    // TEMPLATE, NOT A LIST OF STAGE NAMES.
    //
    // Human decision, 2026-08-11: "a co-agent split may be edited until
    // the referral reaches complete_payment under ITS OWN pipeline
    // template" — derived from BR-4, whose ledger row is written at that
    // stage and immutable thereafter.
    //
    // These five cases are the ones a stage-name list gets wrong (or
    // gets right only by luck), so they are asserted against REAL
    // templates rather than the NULL-snapshot medical fallback.
    // ---------------------------------------------------------------

    public function test_a_direct_sale_referral_can_set_a_co_agent_before_payment(): void
    {
        // Two-stage journey: complete_registered -> complete_payment.
        // Its only pre-payment stage is the entry stage, which the old
        // medical allow-list did happen to contain — so this asserts the
        // capability EXISTS for direct-sale products, by position now
        // rather than by coincidence.
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $template = $this->makeTemplate($company, 'direct_sale', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
        $referral = $this->makeReferral($company, $agent, $template);

        $this->actingAs($agent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 25,
        ])
            ->assertOk()
            ->assertJsonPath('data.co_agent.id', $coAgent->id)
            ->assertJsonPath('data.split_percentage', 25);
    }

    public function test_a_direct_sale_referral_cannot_set_a_co_agent_once_at_payment(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $template = $this->makeTemplate($company, 'direct_sale', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
        $referral = $this->makeReferral($company, $agent, $template);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->actingAs($agent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 25,
        ])->assertUnprocessable()->assertJsonValidationErrors('co_agent_id');

        $this->assertDatabaseHas('referrals', ['id' => $referral->id, 'co_agent_id' => null]);
    }

    public function test_a_medical_referral_can_still_set_a_co_agent_at_the_first_doctor_meeting(): void
    {
        // The pre-ADR-026 journey, unchanged: three stages sit before
        // payment and all three stay editable.
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $template = $this->makeTemplate($company, 'medical_journey', [
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
            PipelineStage::CompletePayment,
        ]);
        $referral = $this->makeReferral($company, $agent, $template);
        $this->advanceToStage($referral, $agent, PipelineStage::Finish1stDoctorMeeting);

        $this->actingAs($agent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 40,
        ])
            ->assertOk()
            ->assertJsonPath('data.co_agent.id', $coAgent->id)
            ->assertJsonPath('data.split_percentage', 40);
    }

    public function test_a_referral_parked_on_a_post_sale_stage_cannot_set_a_co_agent(): void
    {
        // ADR-026 §5 Q1 — `delivery` sits AFTER complete_payment, so the
        // BR-4 ledger row already exists. The old frontend deny-list
        // (`['complete_payment','ongoing_next_meeting']`) offered the
        // control here; the rule says no.
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $template = $this->makeTemplate($company, 'sale_then_delivery', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
            PipelineStage::Delivery,
        ]);
        $referral = $this->makeReferral($company, $agent, $template);
        $this->advanceToStage($referral, $agent, PipelineStage::Delivery);

        $this->actingAs($agent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 25,
        ])->assertUnprocessable()->assertJsonValidationErrors('co_agent_id');

        $this->assertDatabaseHas('referrals', ['id' => $referral->id, 'co_agent_id' => null]);
    }

    public function test_an_unreadable_template_refuses_the_edit_rather_than_allowing_it(): void
    {
        // FAIL CLOSED (§6). The referral is at the entry stage — the most
        // "obviously editable" position there is — but its journey has
        // been emptied out from under it, so we cannot tell whether the
        // BR-4 ledger row has been written. Who gets paid is not a
        // question to answer on a guess.
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $template = $this->makeTemplate($company, 'about_to_be_emptied', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
        $referral = $this->makeReferral($company, $agent, $template);

        PipelineTemplateStage::where('pipeline_template_id', $template->id)->delete();

        $this->actingAs($agent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 25,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('referrals', ['id' => $referral->id, 'co_agent_id' => null]);
    }

    public function test_an_agent_from_another_company_cannot_set_the_co_agent(): void
    {
        // BR-6 / §5.5 — IDOR: guessing the id of a referral in another
        // tenant must not reach this money control at all.
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $foreignCoAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $template = $this->makeTemplate($company, 'direct_sale', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
        $referral = $this->makeReferral($company, $agent, $template);

        $response = $this->actingAs($foreignAgent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => $foreignCoAgent->id,
            'split_percentage' => 25,
        ]);

        // 403 (Policy) or 404 (TenantScope hides the row from route
        // binding) are both correct refusals; a 200 or a 422 would mean
        // the request reached the business rule.
        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseHas('referrals', ['id' => $referral->id, 'co_agent_id' => null]);
    }
}
