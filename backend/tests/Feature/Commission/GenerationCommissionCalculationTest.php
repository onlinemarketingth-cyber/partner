<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\PipelineStage;
use App\Models\AgentRank;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionGenerationRule;
use App\Models\CommissionGenerationSetting;
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

// ADR-011/TASK-031 — Generation MLM plan type: overrides paid by upline
// GENERATION (count of breakaway-rank ancestors walked, not raw
// manager_id hops — see GenerationCommissionService's own docblock),
// gated the same way as Unilevel/Binary/Matrix/Stairstep inside
// CommissionService::recordForReferral().
class GenerationCommissionCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);

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

    public function test_generation_overrides_pay_only_breakaway_ranked_ancestors_per_generation(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Generation->value]);
        CommissionGenerationSetting::factory()->create(['company_id' => $company->id, 'max_generation_depth' => 5]);
        CommissionGenerationRule::factory()->create(['company_id' => $company->id, 'generation_number' => 1, 'rate_value' => 500]); // 5%
        CommissionGenerationRule::factory()->create(['company_id' => $company->id, 'generation_number' => 2, 'rate_value' => 300]); // 3%
        $breakawayRank = AgentRank::factory()->breakaway()->create(['company_id' => $company->id]);

        // Chain (bottom to top): seller -> a (breakaway) -> b (no rank)
        // -> c (breakaway) -> d (no rank, top). Only a and c anchor a
        // generation; b and d earn nothing under this plan.
        $d = User::factory()->agent()->create(['company_id' => $company->id]);
        $c = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $d->id]);
        $b = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $c->id]);
        $a = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $b->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $a->id]);
        $this->setRank($a, $breakawayRank);
        $this->setRank($c, $breakawayRank);

        $this->makeCompletedReferral($company, $seller, 1_000_000);

        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $a->id, 'earned_via' => CommissionEarnedVia::GenerationOverride->value, 'amount_satang' => 50_000,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $c->id, 'earned_via' => CommissionEarnedVia::GenerationOverride->value, 'amount_satang' => 30_000,
        ]);
        $this->assertSame(0, CommissionLedger::where('agent_id', $b->id)->count());
        $this->assertSame(0, CommissionLedger::where('agent_id', $d->id)->count());
        $this->assertSame(2, CommissionLedger::where('earned_via', CommissionEarnedVia::GenerationOverride->value)->count());
    }

    public function test_walk_stops_at_the_configured_max_generation_depth(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Generation->value]);
        CommissionGenerationSetting::factory()->create(['company_id' => $company->id, 'max_generation_depth' => 1]);
        CommissionGenerationRule::factory()->create(['company_id' => $company->id, 'generation_number' => 1, 'rate_value' => 500]);
        CommissionGenerationRule::factory()->create(['company_id' => $company->id, 'generation_number' => 2, 'rate_value' => 300]);
        $breakawayRank = AgentRank::factory()->breakaway()->create(['company_id' => $company->id]);

        $c = User::factory()->agent()->create(['company_id' => $company->id]);
        $a = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $c->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $a->id]);
        $this->setRank($a, $breakawayRank);
        $this->setRank($c, $breakawayRank); // would be generation 2, but max_generation_depth=1 caps the walk.

        $this->makeCompletedReferral($company, $seller, 1_000_000);

        $this->assertSame(1, CommissionLedger::where('earned_via', CommissionEarnedVia::GenerationOverride->value)->count());
        $this->assertDatabaseMissing('commission_ledger', ['agent_id' => $c->id, 'earned_via' => CommissionEarnedVia::GenerationOverride->value]);
    }

    public function test_no_generation_settings_configured_pays_nothing(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Generation->value]);
        // Deliberately no CommissionGenerationSetting row.
        $breakawayRank = AgentRank::factory()->breakaway()->create(['company_id' => $company->id]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $breakawayRank);

        $this->makeCompletedReferral($company, $seller, 1_000_000);

        $this->assertSame(0, CommissionLedger::where('earned_via', CommissionEarnedVia::GenerationOverride->value)->count());
    }

    // TASK-034 QA gap-fill — GenerationCommissionService::MAX_CHAIN_DEPTH
    // (=100) is the walk's real safety net against a corrupted/cyclic
    // manager_id chain (e.g. a data-entry mistake that re-parents an
    // agent back onto their own downline) — every other test in this
    // file only ever builds a short, acyclic chain. Without this test,
    // a future refactor that dropped or miscoded the depth<MAX_CHAIN_DEPTH
    // guard would hang this exact request forever instead of failing
    // loudly; this test's own completion (not timing out the suite) is
    // the actual assertion, backed up by the explicit 0-ledger-rows check.
    public function test_a_cyclic_manager_chain_does_not_infinite_loop(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Generation->value]);
        CommissionGenerationSetting::factory()->create(['company_id' => $company->id, 'max_generation_depth' => 50]);
        CommissionGenerationRule::factory()->create(['company_id' => $company->id, 'generation_number' => 1, 'rate_value' => 500]);

        // Two agents whose manager_id points at each other — neither is
        // breakaway-ranked, so `generation` never increments and only
        // MAX_CHAIN_DEPTH stops the walk (max_generation_depth alone
        // would never fire here).
        $x = User::factory()->agent()->create(['company_id' => $company->id]);
        $y = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $x->id]);
        $x->forceFill(['manager_id' => $y->id])->save();
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $x->id]);

        $this->makeCompletedReferral($company, $seller, 1_000_000);

        $this->assertSame(0, CommissionLedger::where('earned_via', CommissionEarnedVia::GenerationOverride->value)->count());
    }

    public function test_generation_plan_type_does_not_also_fire_unilevel_overrides(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Generation->value]);
        CommissionGenerationSetting::factory()->create(['company_id' => $company->id, 'max_generation_depth' => 5]);
        CommissionGenerationRule::factory()->create(['company_id' => $company->id, 'generation_number' => 1, 'rate_value' => 500]);
        $breakawayRank = AgentRank::factory()->breakaway()->create(['company_id' => $company->id]);
        $managerTier = CertTier::factory()->create();
        CommissionOverrideRule::factory()->create(['company_id' => $company->id, 'manager_cert_tier_id' => $managerTier->id, 'rate_value' => 900]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $manager->id, 'cert_tier_id' => $managerTier->id, 'passed_at' => now()]);
        $this->setRank($manager, $breakawayRank);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);

        $this->makeCompletedReferral($company, $seller, 1_000_000);

        $this->assertSame(0, CommissionLedger::where('earned_via', CommissionEarnedVia::Override->value)->count());
    }
}
