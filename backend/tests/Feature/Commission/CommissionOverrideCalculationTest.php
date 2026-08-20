<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
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

// TASK-025 / ADR-006 — override commission on top of the existing
// direct-sale flow tested in CommissionCalculationTest. BR-4: every
// override is its own separate, immutable ledger row; BR-6: manager_id
// assignment itself is guarded elsewhere (UserService), this file only
// tests what CommissionService does once a valid chain already exists.
class CommissionOverrideCalculationTest extends TestCase
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

    /**
     * TASK-214 — REWRITTEN. This test used to prove that two managers at
     * different cert tiers earned two DIFFERENT override rates (1% and
     * 0.5%), which was the whole point of keying the rate by
     * manager_cert_tier_id.
     *
     * The human removed that on 2026-08-19 ("ไม่ต้องผูก"), for the same
     * reason ADR-035 removed cert tier from the selling agent's rate:
     * passing more exams is not a reason to earn a higher percentage. One
     * company-wide rate now pays every eligible manager in the chain the
     * same percentage.
     *
     * What did NOT change, and is still asserted here: a manager must hold
     * a passed cert tier to be paid at all. Certification is a gate, not a
     * multiplier.
     */
    public function test_a_three_level_chain_pays_every_eligible_manager_the_same_company_wide_rate(): void
    {
        $company = Company::factory()->create();
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $unitManagerTier = CertTier::factory()->create(['key' => 'unit_manager_tier', 'sort_order' => 2]);
        $branchManagerTier = CertTier::factory()->create(['key' => 'branch_manager_tier', 'sort_order' => 3]);

        $branchManager = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($branchManager, $company, $branchManagerTier);

        $unitManager = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $branchManager->id]);
        $this->passCert($unitManager, $company, $unitManagerTier);

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $unitManager->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]); // 10,000 THB
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300, // 3%
        ]);
        // ONE rule, company-wide (both scope columns null) — the shape
        // every pre-TASK-214 row already had.
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id, 'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 100, // 1%
        ]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(3, CommissionLedger::where('referral_id', $referral->id)->count());

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id,
            'earned_via' => CommissionEarnedVia::Direct->value, 'amount_satang' => 30000, 'override_source_agent_id' => null,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $unitManager->id,
            'earned_via' => CommissionEarnedVia::Override->value, 'amount_satang' => 10000, 'override_source_agent_id' => $agent->id,
        ]);
        // Same 1% as the unit manager — no longer 0.5%.
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $branchManager->id,
            'earned_via' => CommissionEarnedVia::Override->value, 'amount_satang' => 10000, 'override_source_agent_id' => $agent->id,
        ]);
    }

    /**
     * TASK-214 — the ruling's actual point: a product may pay its leaders a
     * different rate from the company default, resolved in exactly the
     * order the selling agent's rate uses (product > category > company).
     */
    public function test_a_product_scoped_override_rate_beats_the_company_default(): void
    {
        $company = Company::factory()->create();
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $managerTier = CertTier::factory()->create(['key' => 'unit_manager_tier', 'sort_order' => 2]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($manager, $company, $managerTier);
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]); // 10,000 THB
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);

        // Company-wide default 1% ...
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id, 'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 100,
        ]);
        // ... overridden to 2.5% for THIS product only.
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id, 'manager_cert_tier_id' => null, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 250,
        ]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        // 2.5% of 10,000 THB = 250 THB, not the company default's 100 THB.
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value, 'amount_satang' => 25000,
        ]);
    }

    /**
     * TASK-214 — the gate that survived the ruling. The rate stopped
     * depending on the manager's tier; being paid at all did not.
     */
    public function test_a_manager_with_no_passed_cert_tier_is_still_skipped(): void
    {
        $company = Company::factory()->create();
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]); // never certified
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id, 'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 100,
        ]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseMissing('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $manager->id,
        ]);
    }

    public function test_a_manager_with_no_configured_override_rate_gets_no_row_not_a_zero_row(): void
    {
        $company = Company::factory()->create();
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $managerTier = CertTier::factory()->create(['sort_order' => 2]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($manager, $company, $managerTier);
        // Deliberately no CommissionOverrideRule seeded for $managerTier.

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('commission_ledger', ['referral_id' => $referral->id, 'earned_via' => CommissionEarnedVia::Direct->value]);
    }

    public function test_an_agent_with_no_manager_produces_only_the_direct_row(): void
    {
        // Zero-behavior-change acceptance criterion — a company that
        // never assigns any manager relationship sees no override rows.
        $company = Company::factory()->create();
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]); // manager_id stays null
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 500000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    public function test_removing_a_manager_afterward_does_not_affect_the_historical_override_row(): void
    {
        // BR-4 — commission_ledger rows are immutable once created;
        // manager_id is on `users`, a separate mutable table, so
        // re-parenting the org chart later must never rewrite history.
        $company = Company::factory()->create();
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $managerTier = CertTier::factory()->create(['sort_order' => 2]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($manager, $company, $managerTier);
        CommissionOverrideRule::factory()->create(['company_id' => $company->id, 'manager_cert_tier_id' => $managerTier->id, 'rate_value' => 100]);

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $overrideRow = CommissionLedger::where('referral_id', $referral->id)->where('earned_via', CommissionEarnedVia::Override->value)->firstOrFail();

        $agent->update(['manager_id' => null]);

        $this->assertDatabaseHas('commission_ledger', [
            'id' => $overrideRow->id,
            'agent_id' => $manager->id,
            'amount_satang' => $overrideRow->amount_satang,
        ]);
    }
}
