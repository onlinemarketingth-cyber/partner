<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Commission\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// BR-2 (rate depends on agent's cert tier x package sold, always from
// commission_rules — never hardcoded), BR-4 (immutable ledger entry,
// created once the trigger condition fires), Section 4.3 (triggers at
// Complete Payment).
class CommissionCalculationTest extends TestCase
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

    public function test_reaching_complete_payment_creates_a_commission_ledger_entry_with_the_correct_amount(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]); // 8,900 THB
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300, // 3.00%
        ]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'agent_id' => $agent->id,
            'cert_tier_id_at_time' => $tier->id,
            'product_id' => $product->id,
            'rate_type_applied' => 'percentage',
            'rate_applied' => 300,
            'amount_satang' => 26700, // 890000 * 300 / 10000
            'payment_status' => 'pending',
        ]);
    }

    public function test_fixed_satang_rate_type_computes_correctly(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::FixedSatang, 'rate_value' => 15000,
        ]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'rate_type_applied' => 'fixed_satang',
            'amount_satang' => 15000,
        ]);
    }

    public function test_recording_commission_twice_for_the_same_referral_does_not_duplicate_it(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());

        // Direct second call at the Service level (the pipeline itself
        // can't naturally re-trigger this — Complete Payment isn't
        // re-enterable — but the Service must guard it regardless).
        app(CommissionService::class)->recordForReferral($referral->fresh());

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    public function test_no_commission_recorded_when_no_matching_commission_rule_exists(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        // Deliberately no CommissionRule seeded for this product/tier.

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        // The pipeline advance itself must still succeed — a missing
        // commission_rules row is a config gap, not a reason to block
        // recording that the sale completed.
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_no_commission_recorded_when_agent_has_no_passed_cert_tier(): void
    {
        // Constructed directly (bypassing ReferralService's BR-1 gate,
        // which would normally prevent this state from ever existing
        // via the API) to test CommissionService's defensive branch in
        // isolation.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]); // no cert
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompletePayment,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $result = app(CommissionService::class)->recordForReferral($referral);

        $this->assertNull($result);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    // ADR-035 (2026-08-18, human decision) — commission RATE no longer
    // depends on the agent's cert tier at all; cert tier stays purely
    // BR-1's access gate. This replaces the old
    // test_rate_is_looked_up_by_the_agents_highest_passed_tier, which
    // asserted the exact opposite (a higher tier resolving a different,
    // higher-rate commission_rules row) — that behavior is gone by
    // design, not a regression. "Higher commission for better results"
    // is Stairstep/Breakaway's job now (agent_ranks), not Unilevel's.
    public function test_rate_does_not_depend_on_the_agents_cert_tier_only_product_category_company_scope_does(): void
    {
        $company = Company::factory()->create();
        $agentBasic = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentIntermediate = User::factory()->agent()->create(['company_id' => $company->id]);
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $intermediate = CertTier::firstOrCreate(['key' => 'intermediate'], ['name' => 'Intermediate', 'sort_order' => 2, 'is_mandatory' => false]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agentBasic->id, 'cert_tier_id' => $basic->id, 'passed_at' => now()]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agentIntermediate->id, 'cert_tier_id' => $basic->id, 'passed_at' => now()]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agentIntermediate->id, 'cert_tier_id' => $intermediate->id, 'passed_at' => now()]);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);
        // ONE flat rule for this product scope — no cert_tier_id dimension
        // to it anymore; both agents below resolve to this SAME row.
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => null, 'product_id' => $product->id, 'rate_value' => 500]);

        foreach ([$agentBasic, $agentIntermediate] as $agent) {
            $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
            $referral = Referral::create([
                'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
                'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
                'meeting_number' => null, 'submitted_at' => now(),
            ]);
            $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

            // Same rule, same amount, regardless of which tier the agent
            // holds — 1,000,000 * 500 / 10000 = 50,000 satang either way.
            $this->assertDatabaseHas('commission_ledger', [
                'referral_id' => $referral->id,
                'amount_satang' => 50000,
            ]);
        }
    }

    // ADR-011/TASK-028 — commission_rules resolution order: product ->
    // category -> company-wide default.

    public function test_a_company_wide_default_rule_is_used_when_no_product_or_category_rule_exists(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);

        // Company-wide default only — no product_id, no product_category_id.
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id,
            'product_id' => null, 'product_category_id' => null, 'rate_value' => 250,
        ]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 25000, // 1,000,000 * 250 / 10000
        ]);
    }

    public function test_a_category_rule_is_used_over_the_company_wide_default(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $category = ProductCategory::factory()->for($company)->create();
        $product = Product::factory()->create(['company_id' => $company->id, 'category_id' => $category->id, 'price_satang' => 1000000]);

        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id,
            'product_id' => null, 'product_category_id' => null, 'rate_value' => 250, // company-wide default
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id,
            'product_id' => null, 'product_category_id' => $category->id, 'rate_value' => 400, // category rule
        ]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        // Category rule (4%) wins over the company-wide default (2.5%).
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 40000, // 1,000,000 * 400 / 10000
        ]);
    }

    public function test_a_product_specific_rule_is_used_over_a_category_rule(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $category = ProductCategory::factory()->for($company)->create();
        $product = Product::factory()->create(['company_id' => $company->id, 'category_id' => $category->id, 'price_satang' => 1000000]);

        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id,
            'product_id' => null, 'product_category_id' => $category->id, 'rate_value' => 400, // category rule
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id,
            'product_id' => $product->id, 'product_category_id' => null, 'rate_value' => 700, // product-specific rule
        ]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        // Product-specific rule (7%) wins over the category rule (4%).
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 70000, // 1,000,000 * 700 / 10000
        ]);
    }
}
