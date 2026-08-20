<?php

namespace Tests\Feature\Commission;

use App\Enums\AffiliateOverrideMode;
use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-194 — Affiliate plan's team-leader override. Reuses TASK-025's
// manager_id + CommissionOverrideRule infrastructure unchanged (BR-2);
// this file only tests the two NEW payout maths (additive/deductive) and
// their fail-safes. See CommissionOverrideCalculationTest for the
// pre-existing Unilevel override this task must not regress (run
// separately below, untouched, to prove the shared refactor didn't
// change its output).
class AffiliateOverrideCommissionTest extends TestCase
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

    /** @return array{company: Company, basic: CertTier, managerTier: CertTier, manager: User, agent: User} */
    /**
     * TASK-214 — `$scopeProductId` exists because the override rate is no
     * longer keyed by the manager's cert tier. A test that builds several
     * managers in one company and expects each to get a different rate now
     * has to say WHICH PRODUCT each rate belongs to; before, "a different
     * tier" was enough to keep them apart. Passing null keeps the old
     * behaviour (one company-wide rate), which is what the single-manager
     * tests below want.
     */
    private function makeAgentWithManager(Company $company, int $overrideRateBasisPoints, ?int $scopeProductId = null): array
    {
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $managerTier = CertTier::factory()->create(['sort_order' => 2]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($manager, $company, $managerTier);
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id, 'manager_cert_tier_id' => $managerTier->id,
            'product_id' => $scopeProductId,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => $overrideRateBasisPoints,
        ]);

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passCert($agent, $company, $basic);

        return compact('company', 'basic', 'managerTier', 'manager', 'agent');
    }

    private function makeReferral(Company $company, User $agent, Product $product): Referral
    {
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        return Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
    }

    public function test_additive_mode_pays_the_manager_on_top_of_the_untouched_agent_amount(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Affiliate->value]);
        ['basic' => $basic, 'managerTier' => $managerTier, 'manager' => $manager, 'agent' => $agent] = $this->makeAgentWithManager($company, 1000); // 10%

        $product = Product::factory()->create([
            'company_id' => $company->id, 'price_satang' => 890000, // 8,900 THB
            'affiliate_override_mode' => AffiliateOverrideMode::Additive->value,
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300, // 3%
        ]);

        $referral = $this->makeReferral($company, $agent, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(2, CommissionLedger::where('referral_id', $referral->id)->count());

        // Agent's own row is untouched: 890,000 * 3% = 26,700.
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id,
            'earned_via' => CommissionEarnedVia::Direct->value, 'amount_satang' => 26700,
        ]);

        // Manager's row is paid ON TOP, against the PRODUCT price (same
        // base Unilevel's override uses): 890,000 * 10% = 89,000.
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value, 'amount_satang' => 89000,
            'cert_tier_id_at_time' => $managerTier->id, 'override_source_agent_id' => $agent->id,
        ]);
    }

    public function test_null_affiliate_override_mode_defaults_to_additive(): void
    {
        // Spec §3.1 — NULL on the column is treated as 'additive'.
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Affiliate->value]);
        ['basic' => $basic, 'manager' => $manager, 'agent' => $agent] = $this->makeAgentWithManager($company, 1000);

        $product = Product::factory()->create([
            'company_id' => $company->id, 'price_satang' => 890000,
            'affiliate_override_mode' => null, // deliberately unset
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);

        $referral = $this->makeReferral($company, $agent, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id, 'amount_satang' => 26700,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $manager->id, 'amount_satang' => 89000,
        ]);
    }

    public function test_deductive_mode_carves_the_managers_cut_out_of_the_agent_amount(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Affiliate->value]);
        ['basic' => $basic, 'manager' => $manager, 'agent' => $agent] = $this->makeAgentWithManager($company, 1000); // 10%

        $product = Product::factory()->create([
            'company_id' => $company->id, 'price_satang' => 890000,
            'affiliate_override_mode' => AffiliateOverrideMode::Deductive->value,
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300, // agentAmount = 26,700
        ]);

        $referral = $this->makeReferral($company, $agent, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(2, CommissionLedger::where('referral_id', $referral->id)->count());

        // managerPayout = round(26,700 * 10%) = 2,670. agent row = 26,700 - 2,670 = 24,030.
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value, 'amount_satang' => 2670,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id,
            'earned_via' => CommissionEarnedVia::Direct->value, 'amount_satang' => 24030,
        ]);

        // The whole point of deductive mode: the two rows sum to EXACTLY
        // the original agentAmount — no more, no less (BR-3, spec §3.2).
        $sum = CommissionLedger::where('referral_id', $referral->id)->sum('amount_satang');
        $this->assertSame(26700, $sum);
    }

    /**
     * Spec §4 — "no rounding drift across 3+ differently-priced test
     * cases picked to force non-exact-satang percentages." Uses a
     * FixedSatang base commission rule per case so agentAmount is an
     * arbitrary integer we control directly (not itself the output of a
     * percentage rounding step), then pairs it with an override rate
     * chosen so agentAmount * rate / 10000 is NOT a whole number —
     * exactly the scenario where rounding both sides independently would
     * lose or invent a satang.
     */
    public function test_deductive_mode_rounding_sums_exactly_across_multiple_non_round_price_points(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Affiliate->value]);

        $cases = [
            // [agentAmountSatang, overrideRateBasisPoints, expectedManagerSatang]
            [100001, 333, 3330],   // 100001*333/10000 = 3330.0333... -> 3330
            [77777, 750, 5833],    // 77777*750/10000  = 5833.275   -> 5833
            [123457, 1667, 20580], // 123457*1667/10000 = 205,802,819/10000 = 20580.2819 -> 20580
        ];

        foreach ($cases as [$agentAmountSatang, $overrideRateBasisPoints, $expectedManagerSatang]) {
            // The product comes FIRST now: each case's override rate is
            // scoped to its own product so the three cases stay independent.
            // Under TASK-214 three company-wide rates in one company would
            // be an ambiguous overlap, and all three cases would silently
            // share whichever one resolved.
            $product = Product::factory()->create([
                'company_id' => $company->id, 'price_satang' => 5000000,
                'affiliate_override_mode' => AffiliateOverrideMode::Deductive->value,
            ]);

            ['basic' => $basic, 'manager' => $manager, 'agent' => $agent] = $this->makeAgentWithManager($company, $overrideRateBasisPoints, $product->id);

            CommissionRule::factory()->create([
                'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
                'rate_type' => CommissionRateType::FixedSatang, 'rate_value' => $agentAmountSatang,
            ]);

            $referral = $this->makeReferral($company, $agent, $product);
            $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

            $expectedAgentSatang = $agentAmountSatang - $expectedManagerSatang;

            $this->assertDatabaseHas('commission_ledger', [
                'referral_id' => $referral->id, 'agent_id' => $manager->id,
                'earned_via' => CommissionEarnedVia::Override->value, 'amount_satang' => $expectedManagerSatang,
            ]);
            $this->assertDatabaseHas('commission_ledger', [
                'referral_id' => $referral->id, 'agent_id' => $agent->id,
                'earned_via' => CommissionEarnedVia::Direct->value, 'amount_satang' => $expectedAgentSatang,
            ]);

            $sum = CommissionLedger::where('referral_id', $referral->id)->sum('amount_satang');
            $this->assertSame($agentAmountSatang, $sum, "rows must sum exactly to agentAmount={$agentAmountSatang} (rate {$overrideRateBasisPoints}bp)");
        }
    }

    public function test_no_manager_produces_a_single_ledger_row_in_additive_mode(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Affiliate->value]);
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]); // manager_id stays null
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create([
            'company_id' => $company->id, 'price_satang' => 500000,
            'affiliate_override_mode' => AffiliateOverrideMode::Additive->value,
        ]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id]);

        $referral = $this->makeReferral($company, $agent, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    public function test_no_manager_produces_a_single_ledger_row_in_deductive_mode(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Affiliate->value]);
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]); // manager_id stays null
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create([
            'company_id' => $company->id, 'price_satang' => 500000,
            'affiliate_override_mode' => AffiliateOverrideMode::Deductive->value,
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);

        $referral = $this->makeReferral($company, $agent, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        // Fail-safe: agent's row must be the FULL amount, untouched —
        // deductive mode must never carve out a cut for a manager that
        // doesn't exist.
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id, 'amount_satang' => 15000, // 500,000 * 3%
        ]);
    }

    public function test_no_matching_override_rule_for_the_managers_tier_produces_a_single_row_in_additive_mode(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Affiliate->value]);
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $managerTier = CertTier::factory()->create(['sort_order' => 2]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($manager, $company, $managerTier);
        // Deliberately no CommissionOverrideRule seeded for $managerTier.

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create([
            'company_id' => $company->id, 'price_satang' => 500000,
            'affiliate_override_mode' => AffiliateOverrideMode::Additive->value,
        ]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id]);

        $referral = $this->makeReferral($company, $agent, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('commission_ledger', ['referral_id' => $referral->id, 'earned_via' => CommissionEarnedVia::Direct->value]);
    }

    public function test_no_matching_override_rule_for_the_managers_tier_produces_a_single_row_in_deductive_mode(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Affiliate->value]);
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $managerTier = CertTier::factory()->create(['sort_order' => 2]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($manager, $company, $managerTier);
        // Deliberately no CommissionOverrideRule seeded for $managerTier.

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create([
            'company_id' => $company->id, 'price_satang' => 500000,
            'affiliate_override_mode' => AffiliateOverrideMode::Deductive->value,
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);

        $referral = $this->makeReferral($company, $agent, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        // Fail-safe holds in deductive mode too: no rule => no carve-out,
        // agent keeps the full amount, single row.
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id, 'amount_satang' => 15000,
        ]);
    }

    public function test_manager_with_no_passed_cert_tier_produces_a_single_row_in_deductive_mode(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Affiliate->value]);
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]); // no cert at all
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create([
            'company_id' => $company->id, 'price_satang' => 500000,
            'affiliate_override_mode' => AffiliateOverrideMode::Deductive->value,
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);

        $referral = $this->makeReferral($company, $agent, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id, 'amount_satang' => 15000,
        ]);
    }
}
