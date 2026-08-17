<?php

namespace Tests\Feature\Commission;

use App\Enums\BinaryCycleFrequency;
use App\Enums\BinaryLeg;
use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\BinaryLegVolume;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionBinarySetting;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Commission\BinaryCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-029 — the matched-volume-per-cycle Binary engine, built
// on top of the schema ADR-006 Round 4 left inert. Two halves tested
// separately: creditVolume() (synchronous, fires at Complete Payment
// alongside the normal direct-sale ledger row) and runDueCycles() (the
// scheduled matching-cycle job that actually pays out).
class BinaryCommissionCalculationTest extends TestCase
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

    public function test_a_direct_sale_credits_the_sellers_manager_leg_volume(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id, 'binary_leg' => BinaryLeg::Left->value]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 500000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id, 'rate_value' => 300]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('binary_leg_volumes', [
            'company_id' => $company->id,
            'agent_id' => $manager->id,
            'left_volume_satang' => 500000,
            'right_volume_satang' => 0,
        ]);
    }

    public function test_volume_rolls_up_every_ancestor_credited_on_the_correct_leg(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
        $topManager = User::factory()->agent()->create(['company_id' => $company->id]);
        $midManager = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $topManager->id, 'binary_leg' => BinaryLeg::Right->value]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $midManager->id, 'binary_leg' => BinaryLeg::Left->value]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 200000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        // agent sits on midManager's Left leg.
        $this->assertDatabaseHas('binary_leg_volumes', [
            'agent_id' => $midManager->id, 'left_volume_satang' => 200000, 'right_volume_satang' => 0,
        ]);
        // midManager sits on topManager's Right leg — volume rolls all
        // the way up (no depth cap, ag-lead judgment call documented in
        // BinaryCommissionService::creditVolume()).
        $this->assertDatabaseHas('binary_leg_volumes', [
            'agent_id' => $topManager->id, 'left_volume_satang' => 0, 'right_volume_satang' => 200000,
        ]);
    }

    public function test_binary_plan_type_does_not_also_fire_unilevel_overrides(): void
    {
        // Regression guard for the double-commission bug fixed alongside
        // this task — a company/product on Binary must never ALSO create
        // a Unilevel override ledger row from the same manager_id chain.
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $managerTier = CertTier::factory()->create();
        UserCertification::create(['company_id' => $company->id, 'user_id' => $manager->id, 'cert_tier_id' => $managerTier->id, 'passed_at' => now()]);
        CommissionOverrideRule::factory()->create(['company_id' => $company->id, 'manager_cert_tier_id' => $managerTier->id, 'rate_value' => 500]);

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id, 'binary_leg' => BinaryLeg::Left->value]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 500000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(0, CommissionLedger::where('earned_via', CommissionEarnedVia::Override->value)->count());
        // But the manager's leg volume WAS credited (Binary path fired instead).
        $this->assertDatabaseHas('binary_leg_volumes', ['agent_id' => $manager->id, 'left_volume_satang' => 500000]);
    }

    public function test_a_product_level_binary_override_credits_volume_even_when_company_default_is_unilevel(): void
    {
        // ADR-011/TASK-027 integration — company stays on its default
        // (Unilevel), but this one product is overridden to Binary.
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id, 'binary_leg' => BinaryLeg::Right->value]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create([
            'company_id' => $company->id, 'price_satang' => 300000,
            'commission_plan_type' => CommissionPlanType::Binary->value,
        ]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('binary_leg_volumes', ['agent_id' => $manager->id, 'right_volume_satang' => 300000]);
    }

    public function test_running_due_cycles_creates_a_matched_ledger_entry_at_the_configured_rate(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionBinarySetting::factory()->create([
            'company_id' => $company->id,
            'matched_rate_type' => CommissionRateType::Percentage,
            'matched_rate_value' => 1000, // 10%
            'cycle_frequency' => BinaryCycleFrequency::Weekly,
            'payout_cap_satang' => null,
            'carry_over_unmatched' => true,
        ]);
        BinaryLegVolume::factory()->create([
            'company_id' => $company->id, 'agent_id' => $agent->id,
            'left_volume_satang' => 500000, 'right_volume_satang' => 300000,
            'last_cycle_at' => null,
        ]);

        $processed = app(BinaryCommissionService::class)->runDueCycles();

        $this->assertSame(1, $processed);
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $agent->id,
            'earned_via' => CommissionEarnedVia::BinaryMatch->value,
            'amount_satang' => 30000, // matched 300,000 * 10%
        ]);
        $this->assertDatabaseHas('binary_matching_cycles', [
            'agent_id' => $agent->id,
            'matched_volume_satang' => 300000,
            'unmatched_carried_satang' => 200000, // carry_over_unmatched = true
        ]);
        $this->assertDatabaseHas('binary_leg_volumes', [
            'agent_id' => $agent->id, 'left_volume_satang' => 200000, 'right_volume_satang' => 0,
        ]);
    }

    public function test_payout_cap_limits_the_ledger_amount(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionBinarySetting::factory()->create([
            'company_id' => $company->id,
            'matched_rate_type' => CommissionRateType::Percentage,
            'matched_rate_value' => 5000, // 50%
            'payout_cap_satang' => 10000,
        ]);
        BinaryLegVolume::factory()->create([
            'company_id' => $company->id, 'agent_id' => $agent->id,
            'left_volume_satang' => 1000000, 'right_volume_satang' => 1000000, 'last_cycle_at' => null,
        ]);

        app(BinaryCommissionService::class)->runDueCycles();

        // 50% of the 1,000,000 matched = 500,000, capped down to 10,000.
        $this->assertDatabaseHas('commission_ledger', ['agent_id' => $agent->id, 'amount_satang' => 10000]);
    }

    public function test_carry_over_disabled_flushes_unmatched_volume_to_zero(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionBinarySetting::factory()->create(['company_id' => $company->id, 'carry_over_unmatched' => false]);
        BinaryLegVolume::factory()->create([
            'company_id' => $company->id, 'agent_id' => $agent->id,
            'left_volume_satang' => 700000, 'right_volume_satang' => 300000, 'last_cycle_at' => null,
        ]);

        app(BinaryCommissionService::class)->runDueCycles();

        $this->assertDatabaseHas('binary_leg_volumes', [
            'agent_id' => $agent->id, 'left_volume_satang' => 0, 'right_volume_satang' => 0,
        ]);
        $this->assertDatabaseHas('binary_matching_cycles', [
            'agent_id' => $agent->id, 'unmatched_carried_satang' => 0,
        ]);
    }

    public function test_zero_matched_volume_creates_a_cycle_snapshot_but_no_ledger_row(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionBinarySetting::factory()->create(['company_id' => $company->id]);
        BinaryLegVolume::factory()->create([
            'company_id' => $company->id, 'agent_id' => $agent->id,
            'left_volume_satang' => 400000, 'right_volume_satang' => 0, 'last_cycle_at' => null,
        ]);

        app(BinaryCommissionService::class)->runDueCycles();

        $this->assertDatabaseCount('commission_ledger', 0);
        $this->assertDatabaseHas('binary_matching_cycles', [
            'agent_id' => $agent->id, 'matched_volume_satang' => 0, 'commission_ledger_id' => null,
        ]);
    }

    public function test_a_cycle_not_yet_due_is_skipped(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionBinarySetting::factory()->create(['company_id' => $company->id, 'cycle_frequency' => BinaryCycleFrequency::Weekly]);
        BinaryLegVolume::factory()->create([
            'company_id' => $company->id, 'agent_id' => $agent->id,
            'left_volume_satang' => 500000, 'right_volume_satang' => 500000,
            'last_cycle_at' => now()->subDays(2), // weekly = due after 7 days
        ]);

        $processed = app(BinaryCommissionService::class)->runDueCycles();

        $this->assertSame(0, $processed);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_a_company_with_no_binary_settings_configured_is_never_swept(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        // Deliberately no CommissionBinarySetting row — plan type alone
        // is not enough; runDueCycles() gates on a configured settings
        // row (see its own docblock).
        BinaryLegVolume::factory()->create([
            'company_id' => $company->id, 'agent_id' => $agent->id,
            'left_volume_satang' => 500000, 'right_volume_satang' => 500000, 'last_cycle_at' => null,
        ]);

        $processed = app(BinaryCommissionService::class)->runDueCycles();

        $this->assertSame(0, $processed);
        $this->assertDatabaseCount('commission_ledger', 0);
    }
}
