<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\PipelineStage;
use App\Models\AgentRank;
use App\Models\AgentRankSetting;
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
use App\Services\Commission\StairstepCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-031 — Stairstep/Breakaway MLM plan type: rank-differential
// override (an upline earns the difference between their own rank's rate
// and their downline's rank's rate), gated the same way as Unilevel/
// Binary/Matrix inside CommissionService::recordForReferral(), plus the
// RecalculateAgentRanks scheduled job (StairstepCommissionService::
// recalculateRanks()) that derives users.current_rank_id from trailing
// sales volume.
class StairstepCommissionCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        // firstOrCreate — makeCompletedReferral() calls this on every sale,
        // and test_a_manager_rank_change_between_two_sales_... deliberately
        // reuses the same $seller across two calls; a plain create() would
        // violate the unique(user_id, cert_tier_id) constraint on the
        // second sale (an agent can't "pass Basic" twice in real life
        // either, so re-passing should just no-op, not error).
        UserCertification::firstOrCreate(
            ['user_id' => $agent->id, 'cert_tier_id' => $tier->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        return $tier;
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
     * current_rank_id is deliberately NOT in User::$fillable (system-owned
     * — see User::currentRank()'s own docblock), so tests must bypass mass
     * assignment the same way StairstepCommissionService::recalculateCompanyRanks()
     * does, rather than via factory create()/update() (which would silently no-op).
     */
    private function setRank(User $user, AgentRank $rank): User
    {
        $user->forceFill(['current_rank_id' => $rank->id])->save();

        return $user->fresh();
    }

    private function makeCompletedReferral(Company $company, User $seller, int $priceSatang): Referral
    {
        $tier = $this->passBasicCert($seller, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => $priceSatang]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        return $this->advanceToStage($referral, $seller, PipelineStage::CompletePayment);
    }

    // --- Rank recalculation ---

    public function test_recalculation_promotes_an_agent_whose_trailing_volume_clears_a_higher_rank(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::StairstepBreakaway->value]);
        AgentRankSetting::factory()->create(['company_id' => $company->id, 'trailing_window_days' => 90]);
        AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Bronze', 'volume_threshold' => 0, 'sort_order' => 1, 'rate_value' => 200]);
        $silver = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Silver', 'volume_threshold' => 900_000, 'sort_order' => 2, 'rate_value' => 500]);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->makeCompletedReferral($company, $seller, 1_000_000);

        app(StairstepCommissionService::class)->recalculateRanks();

        $this->assertSame($silver->id, $seller->fresh()->current_rank_id);
    }

    public function test_recalculation_leaves_an_agent_unranked_when_no_threshold_is_cleared(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::StairstepBreakaway->value]);
        AgentRankSetting::factory()->create(['company_id' => $company->id, 'trailing_window_days' => 90]);
        AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Bronze', 'volume_threshold' => 5_000_000, 'sort_order' => 1, 'rate_value' => 200]);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->makeCompletedReferral($company, $seller, 1_000_000);

        app(StairstepCommissionService::class)->recalculateRanks();

        $this->assertNull($seller->fresh()->current_rank_id);
    }

    // --- Differential override payout ---

    public function test_manager_earns_the_rate_differential_between_their_rank_and_their_downlines_rank(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::StairstepBreakaway->value]);
        $bronze = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Bronze', 'rate_value' => 200]); // 2%
        $gold = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Gold', 'rate_value' => 700]); // 7%

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $gold);
        $this->setRank($seller, $bronze);

        $this->makeCompletedReferral($company, $seller, 1_000_000);

        // Differential = 700 - 200 = 500 basis points (5%) of 1,000,000 satang = 50,000.
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id, 'earned_via' => CommissionEarnedVia::StairstepOverride->value, 'amount_satang' => 50_000,
        ]);
    }

    // TASK-034 QA gap-fill — payDifferentialOverride() "only ever READS
    // current_rank_id" (see the Service's own docblock) was never
    // actually exercised across TWO sales with a rank change in
    // between — every existing test sets ranks once, before the one
    // sale it makes. This locks in that a manager's rank promotion
    // mid-cycle (between two of their downline's sales, no scheduled
    // recalculation involved — this is a manual/admin rank change, the
    // same forceFill() path RecalculateAgentRanks itself uses) is
    // reflected on the VERY NEXT sale's differential, not stale from
    // the first.
    public function test_a_manager_rank_change_between_two_sales_changes_the_next_differential_not_the_first(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::StairstepBreakaway->value]);
        $bronze = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Bronze', 'rate_value' => 200]); // 2%
        $gold = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Gold', 'rate_value' => 700]); // 7%
        $platinum = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Platinum', 'rate_value' => 950]); // 9.5%

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $gold);
        $this->setRank($seller, $bronze);

        // Sale 1 — manager is Gold: differential = 700 - 200 = 500 bps (5%).
        $this->makeCompletedReferral($company, $seller, 1_000_000);
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id, 'earned_via' => CommissionEarnedVia::StairstepOverride->value, 'amount_satang' => 50_000,
        ]);

        // Manager promoted to Platinum between the two sales (mid-cycle,
        // no RecalculateAgentRanks run needed — this is exactly what an
        // admin manually re-ranking someone, or the scheduled job firing
        // between two sales, would do).
        $this->setRank($manager, $platinum);

        // Sale 2 — manager is now Platinum: differential = 950 - 200 = 750 bps (7.5%), not the stale 500.
        $this->makeCompletedReferral($company, $seller, 1_000_000);
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id, 'earned_via' => CommissionEarnedVia::StairstepOverride->value, 'amount_satang' => 75_000,
        ]);

        $this->assertSame(2, CommissionLedger::where('agent_id', $manager->id)->where('earned_via', CommissionEarnedVia::StairstepOverride->value)->count());
    }

    public function test_breakaway_rank_stops_paying_the_former_upline_entirely(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::StairstepBreakaway->value]);
        $diamond = AgentRank::factory()->breakaway()->create(['company_id' => $company->id, 'name' => 'Diamond', 'rate_value' => 900]);
        $platinum = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Platinum', 'rate_value' => 950]);

        $formerUpline = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $formerUpline->id]);
        $this->setRank($formerUpline, $platinum);
        $this->setRank($seller, $diamond); // seller has broken away.

        $this->makeCompletedReferral($company, $seller, 1_000_000);

        $this->assertSame(0, CommissionLedger::where('earned_via', CommissionEarnedVia::StairstepOverride->value)->count());
    }

    public function test_stairstep_plan_type_does_not_also_fire_unilevel_overrides(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::StairstepBreakaway->value]);
        $bronze = AgentRank::factory()->create(['company_id' => $company->id, 'rate_value' => 200]);
        $gold = AgentRank::factory()->create(['company_id' => $company->id, 'rate_value' => 700]);
        $managerTier = CertTier::factory()->create();
        CommissionOverrideRule::factory()->create(['company_id' => $company->id, 'manager_cert_tier_id' => $managerTier->id, 'rate_value' => 900]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $manager->id, 'cert_tier_id' => $managerTier->id, 'passed_at' => now()]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $gold);
        $this->setRank($seller, $bronze);

        $this->makeCompletedReferral($company, $seller, 1_000_000);

        $this->assertSame(0, CommissionLedger::where('earned_via', CommissionEarnedVia::Override->value)->count());
    }
}
