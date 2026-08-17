<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\CommissionSplitSetting;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-026 (ADR-006) — split commission between two co-selling agents.
// BR-4: both rows are separate, immutable, earned_via = Direct ledger
// entries; BR-3: the split must never lose or invent satang.
class SplitCommissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TASK-174 — the split is a per-company switch and it ships OFF (D2).
     * Every TASK-026 assertion below is about the feature WHILE IT IS ON, so
     * each test turns it on explicitly. Spec §7: "Split enabled → two rows
     * summing exactly to the single-row amount (existing TASK-026 tests must
     * still pass)."
     */
    private function enableSplit(Company $company): void
    {
        CommissionSplitSetting::create(['company_id' => $company->id, 'is_enabled' => true]);
    }

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

    public function test_a_referral_with_no_co_agent_produces_exactly_one_direct_row(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300, // 3%
        ]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id,
            'earned_via' => CommissionEarnedVia::Direct->value, 'amount_satang' => 30000,
        ]);
    }

    public function test_a_split_referral_produces_two_rows_summing_exactly_to_the_total_with_rounding_remainder_to_the_referring_agent(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($agent, $company, $basic);

        // 333,350 satang * 3% = 10,000.5 -> rounds to 10,001 (not evenly
        // divisible), then split 33%/67% forces a second rounding step —
        // exercises BR-3's "never lose or invent a satang" rule twice.
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 333350]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'co_agent_id' => $coAgent->id, 'split_percentage' => 33,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $rows = CommissionLedger::where('referral_id', $referral->id)->where('earned_via', CommissionEarnedVia::Direct->value)->get();
        $this->assertSame(2, $rows->count());

        $totalAmount = (int) round(333350 * 300 / 10000); // = 10,000.5 -> 10,001
        $coAgentRow = $rows->firstWhere('agent_id', $coAgent->id);
        $referringAgentRow = $rows->firstWhere('agent_id', $agent->id);

        $this->assertNotNull($coAgentRow);
        $this->assertNotNull($referringAgentRow);
        $this->assertSame($totalAmount, $coAgentRow->amount_satang + $referringAgentRow->amount_satang);
        $this->assertSame((int) round($totalAmount * 33 / 100), $coAgentRow->amount_satang);
    }

    public function test_no_manager_override_is_created_for_the_co_agents_own_manager(): void
    {
        // ag-lead scope decision (documented in CommissionService) — a
        // co-agent's own manager chain is out of scope for TASK-026;
        // only the referring agent's manager chain can earn an override.
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $managerTier = CertTier::factory()->create(['sort_order' => 2]);

        $coAgentManager = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($coAgentManager, $company, $managerTier);

        $agent = User::factory()->agent()->create(['company_id' => $company->id]); // no manager
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $coAgentManager->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'co_agent_id' => $coAgent->id, 'split_percentage' => 50,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(2, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseMissing('commission_ledger', ['referral_id' => $referral->id, 'agent_id' => $coAgentManager->id]);
    }
}
