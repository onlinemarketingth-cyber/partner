<?php

namespace Tests\Feature\Engagement;

use App\Console\Commands\PayDueAgentPromotionCredits;
use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Enums\PromotionPayoutTiming;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Models\AgentPromotion;
use App\Models\AgentPromotionCredit;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-042 §3 (Promotion bonus payout, BR-7 confirmed 2026-07-23):
// PromotionBonusService::evaluateForReferral() is hooked into
// PipelineService::advance()'s existing Complete-Payment block, as a
// third block alongside (never replacing) BR-4 commission and bonus XP.
// Every qualifying event creates an agent_promotion_credits row
// regardless of payout_timing; `immediate` also writes the
// commission_ledger row in the same transaction, `monthly_batch` defers
// that write to PayDueAgentPromotionCredits (same pattern as the
// existing Binary/Stairstep/Renewal scheduled commands).
class PromotionBonusPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function passCert(User $agent, Company $company, CertTier $tier): void
    {
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

    /** @return array{0: Company, 1: User, 2: CertTier, 3: Referral} A company + certified agent + a referral sitting at Complete Registered, ready to be advanced. */
    private function setUpAgentAndReferral(int $productPriceSatang = 1000000): array
    {
        $company = Company::factory()->create();
        $basic = CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => $productPriceSatang]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        return [$company, $agent, $basic, $referral];
    }

    /** @return array<string, mixed> */
    private function promotionAttributes(Company $company, PromotionPayoutTiming $timing, array $overrides = []): array
    {
        return array_merge([
            'company_id' => $company->id,
            'product_id' => null,
            'name' => 'New Agent Bonus',
            'description' => null,
            'target_type' => PromotionTargetType::AllAgents,
            'target_cert_tier_id' => null,
            'bonus_type' => CommissionRateType::Percentage,
            'bonus_value' => 1000, // 10.00% — basis points, BR-3
            'payout_timing' => $timing,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'created_by' => null,
        ], $overrides);
    }

    public function test_immediate_promotion_credits_and_pays_in_the_same_request(): void
    {
        [$company, $agent, , $referral] = $this->setUpAgentAndReferral(1000000); // 10,000 THB
        AgentPromotion::create($this->promotionAttributes($company, PromotionPayoutTiming::Immediate));

        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $credit = AgentPromotionCredit::where('referral_id', $referral->id)->first();
        $this->assertNotNull($credit);
        $this->assertSame(100000, $credit->bonus_amount_satang); // 10% of 10,000 THB
        $this->assertNotNull($credit->paid_at);
        $this->assertNotNull($credit->commission_ledger_id);

        $this->assertDatabaseHas('commission_ledger', [
            'id' => $credit->commission_ledger_id,
            'referral_id' => $referral->id,
            'agent_id' => $agent->id,
            'earned_via' => CommissionEarnedVia::PromotionBonus->value,
            'amount_satang' => 100000,
            'source_agent_promotion_id' => $credit->agent_promotion_id,
        ]);
    }

    public function test_monthly_batch_promotion_credits_but_does_not_pay_yet(): void
    {
        [$company, $agent, , $referral] = $this->setUpAgentAndReferral(1000000);
        AgentPromotion::create($this->promotionAttributes($company, PromotionPayoutTiming::MonthlyBatch));

        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $credit = AgentPromotionCredit::where('referral_id', $referral->id)->first();
        $this->assertNotNull($credit);
        $this->assertSame(100000, $credit->bonus_amount_satang);
        $this->assertNull($credit->paid_at);
        $this->assertNull($credit->commission_ledger_id);

        $this->assertSame(0, CommissionLedger::where('referral_id', $referral->id)
            ->where('earned_via', CommissionEarnedVia::PromotionBonus->value)->count());
    }

    public function test_pay_due_agent_promotion_credits_command_pays_the_deferred_credit(): void
    {
        [$company, $agent, , $referral] = $this->setUpAgentAndReferral(1000000);
        AgentPromotion::create($this->promotionAttributes($company, PromotionPayoutTiming::MonthlyBatch));
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $credit = AgentPromotionCredit::where('referral_id', $referral->id)->first();
        $this->assertNull($credit->paid_at);

        $this->artisan(PayDueAgentPromotionCredits::class)->assertSuccessful();

        $credit->refresh();
        $this->assertNotNull($credit->paid_at);
        $this->assertNotNull($credit->commission_ledger_id);
        $this->assertDatabaseHas('commission_ledger', [
            'id' => $credit->commission_ledger_id,
            'referral_id' => $referral->id,
            'earned_via' => CommissionEarnedVia::PromotionBonus->value,
            'amount_satang' => 100000,
        ]);

        // Running the command again must not double-pay an already-paid credit.
        $this->artisan(PayDueAgentPromotionCredits::class)->assertSuccessful();
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)
            ->where('earned_via', CommissionEarnedVia::PromotionBonus->value)->count());
    }

    public function test_a_referral_from_an_agent_with_the_wrong_cert_tier_creates_no_credit(): void
    {
        [$company, $agent, , $referral] = $this->setUpAgentAndReferral(1000000);
        $highTier = CertTier::create(['key' => 'high', 'name' => 'High', 'sort_order' => 3, 'is_mandatory' => false]);

        AgentPromotion::create($this->promotionAttributes($company, PromotionPayoutTiming::Immediate, [
            'target_type' => PromotionTargetType::CertTier,
            'target_cert_tier_id' => $highTier->id, // agent only passed Basic, not High
        ]));

        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseCount('agent_promotion_credits', 0);
    }

    public function test_an_inactive_draft_promotion_creates_no_credit(): void
    {
        [$company, $agent, , $referral] = $this->setUpAgentAndReferral(1000000);
        AgentPromotion::create($this->promotionAttributes($company, PromotionPayoutTiming::Immediate, [
            'status' => PromotionStatus::Draft,
        ]));

        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseCount('agent_promotion_credits', 0);
    }

    public function test_a_promotion_outside_its_date_range_creates_no_credit(): void
    {
        [$company, $agent, , $referral] = $this->setUpAgentAndReferral(1000000);
        AgentPromotion::create($this->promotionAttributes($company, PromotionPayoutTiming::Immediate, [
            'starts_at' => now()->addWeek(), // not active yet
        ]));

        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseCount('agent_promotion_credits', 0);
    }

    public function test_a_promotion_belonging_to_company_b_never_pays_out_for_an_agent_in_company_a(): void
    {
        [$companyA, $agentA, , $referral] = $this->setUpAgentAndReferral(1000000);
        $companyB = Company::factory()->create();
        AgentPromotion::create($this->promotionAttributes($companyB, PromotionPayoutTiming::Immediate));

        $this->advanceToStage($referral, $agentA, PipelineStage::CompletePayment);

        $this->assertDatabaseCount('agent_promotion_credits', 0);
    }
}
