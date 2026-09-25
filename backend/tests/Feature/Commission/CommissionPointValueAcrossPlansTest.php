<?php

namespace Tests\Feature\Commission;

use App\Enums\BinaryLeg;
use App\Enums\CommissionBasis;
use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\PipelineStage;
use App\Enums\PromotionStatus;
use App\Models\AgentRank;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionGenerationRule;
use App\Models\CommissionGenerationSetting;
use App\Models\CommissionLedger;
use App\Models\CommissionMatrixLevelRate;
use App\Models\CommissionMatrixSetting;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductPricePromotion;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every plan, on the PV basis. The net that was missing.
 *
 * ═══ WHY THIS FILE EXISTS ═══
 *
 * CommissionService hands the PV-aware commission base to all five structural
 * engines, and says so in its own comment: "every engine below takes the
 * COMMISSION BASE, never the sale price". Nothing tested it. The PV suite
 * (CommissionPointValueBasisTest) never mentions Binary, Matrix, Stairstep,
 * Generation or Affiliate; the four per-plan calculation suites never mention
 * PV. Between them sat the one thing that would actually cost money — a plan
 * quietly paying a percentage of the price on a company that promised a
 * percentage of PV — and no test would have failed.
 *
 * It matters more than an ordinary coverage gap because of BR-4: the number
 * these engines produce lands in a row that may never be edited afterwards. A
 * regression here is not a bug to fix, it is money already paid to the wrong
 * person in the wrong amount, with an audit trail proving it.
 *
 * ═══ HOW IT IS BUILT SO IT CANNOT PASS BY ACCIDENT ═══
 *
 * PV is deliberately 400,000 against a price of 1,000,000 — a 2.5x gap with no
 * common factors that matter at these rates. Any engine that reaches for the
 * price instead of the base produces a number that is not merely wrong but
 * unmistakably wrong, and every assertion below names the arithmetic that
 * produced it so a failure says which of the two it used.
 *
 * The promotion tests are the other half of the same guarantee: on PV a
 * discount must move the customer's price and leave every payout alone. That
 * is the single behaviour a company adopts PV for, and it is asserted here
 * against the engines rather than only against the resolver that implements it.
 */
class CommissionPointValueAcrossPlansTest extends TestCase
{
    private const PRICE = 1_000_000;   // 10,000 THB

    private const PV = 400_000;        // 4,000 PV — deliberately unlike the price

    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::firstOrCreate(
            ['user_id' => $agent->id, 'cert_tier_id' => $tier->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        return $tier;
    }

    private function advanceToStage(Referral $referral, User $agent, PipelineStage $target): Referral
    {
        // Crosses Complete Payment through a confirmed order — see
        // TestCase::closeSale() for why no test may press its way past it.
        return $this->advanceReferralTo($referral, $agent, $target);
    }

    private function pvCompany(CommissionPlanType $plan): Company
    {
        return Company::factory()->create([
            'commission_plan_type' => $plan->value,
            'commission_basis' => CommissionBasis::PointValue->value,
        ]);
    }

    /**
     * One completed sale on a PV company.
     *
     * $pvSatang null models the documented fallback (a PV company selling a
     * product nobody has priced in points yet); $discountedSatang adds a live
     * price promotion, which must reach the customer's price and stop there.
     */
    private function sell(
        Company $company,
        User $seller,
        ?int $pvSatang = self::PV,
        ?int $discountedSatang = null,
    ): Referral {
        $tier = $this->passBasicCert($seller, $company);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'price_satang' => self::PRICE,
            'pv_satang' => $pvSatang,
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
        ]);

        if ($discountedSatang !== null) {
            ProductPricePromotion::create([
                'company_id' => $company->id,
                'product_id' => $product->id,
                'discounted_price_satang' => $discountedSatang,
                'note' => null,
                'status' => PromotionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
                'created_by' => null,
            ]);
        }

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id,
            'product_id' => $product->id, 'branch' => 'Silom', 'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ]);

        return $this->advanceToStage($referral, $seller, PipelineStage::CompletePayment);
    }

    private function overrideRate(Company $company, int $basisPoints): void
    {
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'rate_value' => $basisPoints,
        ]);
    }

    // ── Unilevel ────────────────────────────────────────────────────────────

    public function test_a_unilevel_override_is_a_percentage_of_pv_not_of_the_price(): void
    {
        $company = $this->pvCompany(CommissionPlanType::Unilevel);
        $this->overrideRate($company, 100); // 1%

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passBasicCert($manager, $company);

        $this->sell($company, $seller);

        // 1% of 400,000 PV = 4,000. On the price it would have been 10,000.
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value,
            'amount_satang' => 4_000,
        ]);
    }

    public function test_a_discount_moves_the_price_and_leaves_the_unilevel_override_alone(): void
    {
        $company = $this->pvCompany(CommissionPlanType::Unilevel);
        $this->overrideRate($company, 100);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passBasicCert($manager, $company);

        $this->sell($company, $seller, discountedSatang: 600_000); // half price to the customer

        // Still 1% of 400,000 — the whole reason a company adopts PV.
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value,
            'amount_satang' => 4_000,
        ]);

        // …while the row still records what the customer actually paid.
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'sale_price_satang_at_time' => 600_000,
            'commission_base_satang_at_time' => self::PV,
            'commission_basis_at_time' => CommissionBasis::PointValue->value,
        ]);
    }

    public function test_a_product_with_no_pv_falls_back_to_the_sale_price_and_says_so_on_the_row(): void
    {
        $company = $this->pvCompany(CommissionPlanType::Unilevel);
        $this->overrideRate($company, 100);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passBasicCert($manager, $company);

        $this->sell($company, $seller, pvSatang: null);

        // 1% of the 1,000,000 price, because there is no PV to use…
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value,
            'amount_satang' => 10_000,
        ]);

        // …but the row still records that a PV rule was in force, which is the
        // only thing that makes the gap visible after the money is paid.
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'commission_basis_at_time' => CommissionBasis::PointValue->value,
        ]);
    }

    // ── Affiliate ───────────────────────────────────────────────────────────

    public function test_an_affiliate_override_is_a_percentage_of_pv(): void
    {
        $company = $this->pvCompany(CommissionPlanType::Affiliate);
        $this->overrideRate($company, 300); // 3%

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passBasicCert($manager, $company);

        $this->sell($company, $seller);

        // 3% of 400,000 PV = 12,000; on the price it would have been 30,000.
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value,
            'amount_satang' => 12_000,
        ]);
    }

    // ── Binary ──────────────────────────────────────────────────────────────

    public function test_binary_leg_volume_accumulates_in_pv(): void
    {
        $company = $this->pvCompany(CommissionPlanType::Binary);
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create([
            'company_id' => $company->id, 'manager_id' => $manager->id, 'binary_leg' => BinaryLeg::Left->value,
        ]);

        $this->sell($company, $seller);

        $this->assertDatabaseHas('binary_leg_volumes', [
            'company_id' => $company->id,
            'agent_id' => $manager->id,
            'left_volume_satang' => self::PV,
            'right_volume_satang' => 0,
        ]);
    }

    public function test_a_discount_cannot_shrink_a_binary_leg_on_pv(): void
    {
        $company = $this->pvCompany(CommissionPlanType::Binary);
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create([
            'company_id' => $company->id, 'manager_id' => $manager->id, 'binary_leg' => BinaryLeg::Left->value,
        ]);

        $this->sell($company, $seller, discountedSatang: 600_000);

        // A leg that shrank whenever marketing ran a promotion would make the
        // matching cycle a lottery; CommissionService documents this as the
        // point of crediting the base rather than the price.
        $this->assertDatabaseHas('binary_leg_volumes', [
            'agent_id' => $manager->id,
            'left_volume_satang' => self::PV,
        ]);
    }

    // ── Matrix ──────────────────────────────────────────────────────────────

    public function test_matrix_level_rates_apply_to_pv(): void
    {
        $company = $this->pvCompany(CommissionPlanType::Matrix);
        CommissionMatrixSetting::factory()->create(['company_id' => $company->id, 'width' => 3, 'depth' => 2]);
        CommissionMatrixLevelRate::factory()->create(['company_id' => $company->id, 'level' => 1, 'rate_value' => 500]); // 5%
        CommissionMatrixLevelRate::factory()->create(['company_id' => $company->id, 'level' => 2, 'rate_value' => 300]); // 3%

        $level2 = User::factory()->agent()->create(['company_id' => $company->id]);
        $level1 = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id]);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/users/{$level1->id}", ['manager_id' => $level2->id])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/users/{$seller->id}", ['manager_id' => $level1->id])->assertOk();

        $this->sell($company, $seller);

        // 5% and 3% of 400,000 PV — 20,000 and 12,000, not 50,000 and 30,000.
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $level1->id, 'earned_via' => CommissionEarnedVia::MatrixOverride->value, 'amount_satang' => 20_000,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $level2->id, 'earned_via' => CommissionEarnedVia::MatrixOverride->value, 'amount_satang' => 12_000,
        ]);
    }

    // ── Stairstep ───────────────────────────────────────────────────────────

    public function test_the_stairstep_differential_applies_to_pv(): void
    {
        $company = $this->pvCompany(CommissionPlanType::StairstepBreakaway);
        $bronze = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Bronze', 'rate_value' => 200]); // 2%
        $gold = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'Gold', 'rate_value' => 700]); // 7%

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $manager->forceFill(['current_rank_id' => $gold->id])->save();
        $seller->forceFill(['current_rank_id' => $bronze->id])->save();

        $this->sell($company, $seller);

        // Differential 5% of 400,000 PV = 20,000; on the price, 50,000.
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::StairstepOverride->value,
            'amount_satang' => 20_000,
        ]);
    }

    // ── Generation ──────────────────────────────────────────────────────────

    public function test_generation_overrides_apply_to_pv(): void
    {
        $company = $this->pvCompany(CommissionPlanType::Generation);
        CommissionGenerationSetting::factory()->create(['company_id' => $company->id, 'max_generation_depth' => 5]);
        CommissionGenerationRule::factory()->create(['company_id' => $company->id, 'generation_number' => 1, 'rate_value' => 500]); // 5%
        $breakaway = AgentRank::factory()->breakaway()->create(['company_id' => $company->id]);

        $anchor = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $anchor->id]);
        $anchor->forceFill(['current_rank_id' => $breakaway->id])->save();

        $this->sell($company, $seller);

        // 5% of 400,000 PV = 20,000; on the price, 50,000.
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $anchor->id,
            'earned_via' => CommissionEarnedVia::GenerationOverride->value,
            'amount_satang' => 20_000,
        ]);
    }

    // ── The guarantee that holds across all of them ─────────────────────────

    public function test_a_price_basis_company_is_untouched_by_any_of_this(): void
    {
        // The safety argument for the whole PV feature: a company that never
        // opted in computes exactly what it did before PV existed. Same setup
        // as the Unilevel test above, one column different.
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'commission_basis' => CommissionBasis::Price->value,
        ]);
        $this->overrideRate($company, 100);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->passBasicCert($manager, $company);

        // The product carries a PV, and it must be ignored entirely.
        $this->sell($company, $seller);

        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value,
            'amount_satang' => 10_000, // 1% of the 1,000,000 price
        ]);
        $this->assertSame(
            0,
            CommissionLedger::withoutGlobalScopes()
                ->where('commission_basis_at_time', CommissionBasis::PointValue->value)->count(),
            'a price-basis company must never write a PV row',
        );
    }
}
