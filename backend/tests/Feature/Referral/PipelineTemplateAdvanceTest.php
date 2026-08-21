<?php

namespace Tests\Feature\Referral;

use App\Enums\CommissionRateType;
use App\Enums\GamificationSourceType;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\GamificationRule;
use App\Models\Order;
use App\Models\PipelineStageLog;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Models\XpLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-026 §3.6 / §3.7 (TASK-133) — PipelineService::advance() and
 * OrderService::confirmPayment() obey the referral's OWN pipeline
 * template instead of a hardcoded five-stage medical journey.
 *
 * The first test in this file is the one that matters most in the whole
 * sprint: a `medical_package_default` referral must behave BIT-IDENTICALLY
 * to how it behaved before ADR-026. A regression there silently changes
 * how every existing Thai Life sale works.
 */
class PipelineTemplateAdvanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A certified agent (BR-1) with a product priced at 8,900 THB and a
     * 3% commission rule, so reaching Complete Payment can actually pay
     * out and BR-4's ledger row is observable.
     *
     * @return array{0: Company, 1: User, 2: Product}
     */
    private function makeCompanyAgentProduct(): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        // BR-3 — integer satang, never float.
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);

        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300, // 3%
        ]);

        return [$company, $agent, $product];
    }

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
     * Created directly (not via ReferralService) so a test can place the
     * referral at any stage of any template without walking there first
     * — same shortcut PipelineTest/OrderTest already take.
     */
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
            'preferred_time' => now()->addDay(),
            'current_stage' => $stage,
            'meeting_number' => null,
            'submitted_at' => now(),
            'pipeline_template_id' => $template?->id,
        ]);
    }

    /** CLAUDE.md §4.3's original five stages, verbatim (= PipelineTemplateSeeder's medical_package_default). */
    private function medicalTemplate(Company $company): PipelineTemplate
    {
        return $this->makeTemplate($company, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
        ]);
    }

    private function directSaleTemplate(Company $company): PipelineTemplate
    {
        return $this->makeTemplate($company, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
    }

    // ---------------------------------------------------------------
    // THE regression test — medical journey must not have changed.
    // ---------------------------------------------------------------

    public function test_a_medical_template_referral_walks_the_five_stages_exactly_as_before(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $referral = $this->makeReferral($company, $agent, $product, $this->medicalTemplate($company));

        $walk = [
            'waiting_appointment',
            'finish_1st_doctor_meeting',
            'complete_payment',
            'ongoing_next_meeting',
        ];

        foreach ($walk as $index => $stage) {
            $this->actingAs($agent)
                ->postJson("/api/v1/referrals/{$referral->id}/advance")
                ->assertOk()
                ->assertJsonPath('data.current_stage.key', $stage);

            // BR-4 — the ledger row appears at complete_payment and at no
            // earlier stage.
            $expectedLedgerRows = $index >= 2 ? 1 : 0;
            $this->assertSame(
                $expectedLedgerRows,
                CommissionLedger::where('referral_id', $referral->id)->count(),
                "commission_ledger row count after advancing to {$stage}",
            );
        }

        $referral->refresh();
        $this->assertSame(PipelineStage::OngoingNextMeeting, $referral->current_stage);
        // First entry into Ongoing Next Meeting is "the 2nd meeting".
        $this->assertSame(2, $referral->meeting_number);

        // The self-loop still works and still increments meeting_number.
        $this->actingAs($agent)
            ->postJson("/api/v1/referrals/{$referral->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.current_stage.key', 'ongoing_next_meeting')
            ->assertJsonPath('data.meeting_number', 3);

        // §6 Audit Log — one row per transition (5 advances here; this
        // helper does not write the creation row).
        $this->assertSame(5, PipelineStageLog::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('pipeline_stage_logs', [
            'referral_id' => $referral->id,
            'from_stage' => 'finish_1st_doctor_meeting',
            'to_stage' => 'complete_payment',
            'changed_by_user_id' => $agent->id,
        ]);

        // BR-4 — exactly one commission row, at 3% of 8,900 THB.
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'agent_id' => $agent->id,
            'amount_satang' => 26700,
        ]);
    }

    public function test_confirming_payment_too_early_on_a_medical_template_keeps_the_medical_message(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $referral = $this->makeReferral(
            $company,
            $agent,
            $product,
            $this->medicalTemplate($company),
            PipelineStage::WaitingAppointment,
        );
        // AwaitingVerification, not Pending: since the 2026-08-21 audit an
        // order with no slip is refused BEFORE the stage is even looked at,
        // and this test is about the STAGE gate. Starting from Pending would
        // still go red, for the wrong reason, and stop testing this.
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('errors.referral.0', 'ต้องผ่านขั้น "พบแพทย์ครั้งแรก" ก่อนจึงจะยืนยันการชำระเงินได้');

        $this->assertSame(OrderStatus::AwaitingVerification, $order->fresh()->status);
        $this->assertSame(PipelineStage::WaitingAppointment, $referral->fresh()->current_stage);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_confirming_payment_from_finish_1st_doctor_meeting_still_fires_commission_once(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $referral = $this->makeReferral(
            $company,
            $agent,
            $product,
            $this->medicalTemplate($company),
            PipelineStage::Finish1stDoctorMeeting,
        );
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(PipelineStage::CompletePayment, $referral->fresh()->current_stage);
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    // ---------------------------------------------------------------
    // direct_sale_default — the journey ADR-026 exists to unblock.
    // ---------------------------------------------------------------

    public function test_a_direct_sale_referral_advances_straight_from_registration_to_payment(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $referral = $this->makeReferral($company, $agent, $product, $this->directSaleTemplate($company));

        $this->actingAs($agent)
            ->postJson("/api/v1/referrals/{$referral->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.current_stage.key', 'complete_payment');

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('pipeline_stage_logs', [
            'referral_id' => $referral->id,
            'from_stage' => 'complete_registered',
            'to_stage' => 'complete_payment',
        ]);
    }

    public function test_confirm_payment_succeeds_immediately_on_a_direct_sale_referral(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        // Still at the entry stage — under the OLD hardcoded rule this
        // was a hard 422 (ADR-026 §1: "no customer can ever complete a
        // payment on their own").
        $referral = $this->makeReferral($company, $agent, $product, $this->directSaleTemplate($company));
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(PipelineStage::CompletePayment, $referral->fresh()->current_stage);
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    // ---------------------------------------------------------------
    // Post-sale stages (ADR-026 §5 Q1) — ordinary XP, no extra money.
    // ---------------------------------------------------------------

    public function test_a_post_sale_template_walks_past_payment_without_paying_commission_again(): void
    {
        GamificationRule::create(['company_id' => null, 'source_type' => GamificationSourceType::PipelineStageAdvanced, 'xp_value' => 10, 'is_active' => true]);
        GamificationRule::create(['company_id' => null, 'source_type' => GamificationSourceType::PaymentComplete, 'xp_value' => 50, 'is_active' => true]);

        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->makeTemplate($company, 'sell_then_deliver', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
            PipelineStage::Delivery,
            PipelineStage::FollowUp,
        ]);
        $referral = $this->makeReferral($company, $agent, $product, $template);

        foreach (['complete_payment', 'delivery', 'follow_up'] as $stage) {
            $this->actingAs($agent)
                ->postJson("/api/v1/referrals/{$referral->id}/advance")
                ->assertOk()
                ->assertJsonPath('data.current_stage.key', $stage);
        }

        // BR-4 — commission fired at complete_payment and nowhere else.
        // The two post-sale transitions add nothing.
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());

        // BR-5 — ordinary per-stage XP for all three transitions...
        $this->assertSame(3, XpLedger::where('user_id', $agent->id)
            ->where('source_type', GamificationSourceType::PipelineStageAdvanced->value)
            ->count());
        // ...and the PaymentComplete bonus exactly once, at complete_payment
        // only (ADR-026 §5 Q1: post-sale stages get no separate bonus).
        $this->assertSame(1, XpLedger::where('user_id', $agent->id)
            ->where('source_type', GamificationSourceType::PaymentComplete->value)
            ->count());

        // §6 Audit Log — every transition, including the post-sale ones.
        $this->assertSame(3, PipelineStageLog::where('referral_id', $referral->id)->count());
    }

    public function test_a_referral_at_the_final_stage_of_its_template_is_refused_not_silently_ignored(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->makeTemplate($company, 'ends_in_follow_up', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
            PipelineStage::FollowUp,
        ]);
        $referral = $this->makeReferral($company, $agent, $product, $template, PipelineStage::FollowUp);

        $this->actingAs($agent)
            ->postJson("/api/v1/referrals/{$referral->id}/advance")
            ->assertUnprocessable();

        // Nothing moved, nothing was logged, no money was written.
        $this->assertSame(PipelineStage::FollowUp, $referral->fresh()->current_stage);
        $this->assertSame(0, PipelineStageLog::where('referral_id', $referral->id)->count());
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_confirming_payment_on_a_referral_already_past_payment_does_not_double_pay(): void
    {
        // "Past it" must be read from the TEMPLATE's ordering, not enum
        // case order (ADR-026 §3.7): `delivery` sits after
        // `complete_payment` here purely because the template says so.
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->makeTemplate($company, 'sell_then_deliver', [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
            PipelineStage::Delivery,
        ]);
        $referral = $this->makeReferral($company, $agent, $product, $template, PipelineStage::Delivery);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        // The order is paid, but the referral was NOT advanced again
        // (it is already past payment) and no commission was written by
        // this call — BR-4 is a side effect of reaching Complete Payment.
        $this->assertSame(PipelineStage::Delivery, $referral->fresh()->current_stage);
        $this->assertDatabaseCount('commission_ledger', 0);
        $this->assertSame(0, PipelineStageLog::where('referral_id', $referral->id)->count());
    }

    // ---------------------------------------------------------------
    // Legacy rows + fail-closed.
    // ---------------------------------------------------------------

    public function test_a_legacy_referral_with_no_template_still_walks_the_default_journey(): void
    {
        // ADR-026 §3.6 — pipeline_template_id IS NULL on every
        // pre-ADR-026 referral; it must keep using PipelineStage's own
        // default edges.
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $referral = $this->makeReferral($company, $agent, $product, null);

        foreach (['waiting_appointment', 'finish_1st_doctor_meeting', 'complete_payment', 'ongoing_next_meeting'] as $stage) {
            $this->actingAs($agent)
                ->postJson("/api/v1/referrals/{$referral->id}/advance")
                ->assertOk()
                ->assertJsonPath('data.current_stage.key', $stage);
        }

        $referral->refresh();
        $this->assertNull($referral->pipeline_template_id);
        $this->assertSame(2, $referral->meeting_number);
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    public function test_a_legacy_referral_cannot_confirm_payment_before_the_first_doctor_meeting(): void
    {
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $referral = $this->makeReferral($company, $agent, $product, null, PipelineStage::WaitingAppointment);
        // See the note on the medical stage-gate test above — the slip check
        // now runs first, so this has to clear it to reach the stage check.
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('errors.referral.0', 'ต้องผ่านขั้น "พบแพทย์ครั้งแรก" ก่อนจึงจะยืนยันการชำระเงินได้');

        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_advance_fails_closed_when_the_templates_stages_were_emptied_by_hand(): void
    {
        // TASK-135's fail-closed case: the Service must refuse, never
        // silently skip to payment.
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $template = $this->directSaleTemplate($company);
        $referral = $this->makeReferral($company, $agent, $product, $template);

        PipelineTemplateStage::where('pipeline_template_id', $template->id)->delete();

        $this->actingAs($agent)
            ->postJson("/api/v1/referrals/{$referral->id}/advance")
            ->assertUnprocessable();

        $this->assertSame(PipelineStage::CompleteRegistered, $referral->fresh()->current_stage);
        $this->assertDatabaseCount('commission_ledger', 0);
        $this->assertSame(0, PipelineStageLog::where('referral_id', $referral->id)->count());
    }

    public function test_advance_fails_closed_when_the_current_stage_is_not_part_of_the_template(): void
    {
        // A referral hand-parked at a stage its own journey does not
        // contain has no defensible "next" — refuse rather than guess
        // (ADR-026 §3.4).
        [$company, $agent, $product] = $this->makeCompanyAgentProduct();
        $referral = $this->makeReferral(
            $company,
            $agent,
            $product,
            $this->directSaleTemplate($company),
            PipelineStage::WaitingAppointment,
        );

        $this->actingAs($agent)
            ->postJson("/api/v1/referrals/{$referral->id}/advance")
            ->assertUnprocessable();

        $this->assertSame(PipelineStage::WaitingAppointment, $referral->fresh()->current_stage);
        $this->assertDatabaseCount('commission_ledger', 0);
    }
}
