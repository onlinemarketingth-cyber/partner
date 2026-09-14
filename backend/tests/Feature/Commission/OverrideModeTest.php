<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionOverrideMode;
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
use App\Services\Commission\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-13 — WHERE THE TEAM LEADER'S SHARE COMES FROM.
 *
 * The owner read a worked example of the old behaviour and asked for the other
 * two ("ปรับได้ทั้งหักจากสมชายปิดการขาย และบริษัทจ่ายเพิ่ม"). Every test below
 * uses the SAME sale — 10,000 baht, seller 3%, leader 2% — because the whole
 * point is that one set of inputs produces three different answers, and the two
 * deduct modes differ from each other by 33x.
 *
 *   Additive              seller 300 · leader 200 · company pays 500
 *   DeductFromSale        seller 100 · leader 200 · company pays 300
 *   DeductFromCommission  seller 294 · leader   6 · company pays 300
 *
 * If these three ever converge, the setting has stopped doing anything and
 * every company is silently back on whichever one survived.
 */
class OverrideModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_pays_the_leader_on_top_and_leaves_the_seller_whole(): void
    {
        // Today's behaviour for every company that existed before this feature,
        // asserted first: nothing about the deduct modes is opt-out, and this
        // is the test that would fail if one leaked in as a default.
        $world = $this->world(CommissionOverrideMode::Additive);

        $this->sell($world);

        $this->assertSame(30000, $this->directAmount($world));
        $this->assertSame(20000, $this->overrideAmount($world));
    }

    public function test_deduct_from_sale_takes_the_leaders_percentage_of_the_sale_out_of_the_seller(): void
    {
        /*
         * The mode the owner's question was really about. The leader is paid
         * the SAME 200 as under Additive — it is a percentage of the sale
         * either way — and the difference is entirely in who funds it.
         */
        $world = $this->world(CommissionOverrideMode::DeductFromSale);

        $this->sell($world);

        $this->assertSame(10000, $this->directAmount($world), 'seller keeps 300 - 200');
        $this->assertSame(20000, $this->overrideAmount($world), 'leader still gets 2% of the sale');
    }

    public function test_deduct_from_commission_takes_a_percentage_of_the_sellers_commission(): void
    {
        // The mode TASK-194 already shipped for Affiliate, now available to
        // Unilevel too. 2% of 300, not 2% of 10,000 — the 33x difference that
        // makes naming the base in the enum worth the longer case names.
        $world = $this->world(CommissionOverrideMode::DeductFromCommission);

        $this->sell($world);

        $this->assertSame(29400, $this->directAmount($world), 'seller keeps 300 - 6');
        $this->assertSame(600, $this->overrideAmount($world), '2% of the commission, not of the sale');
    }

    public function test_the_two_deduct_modes_never_cost_the_company_more_than_the_seller_rate(): void
    {
        /*
         * The property that makes "deduct" mean something, stated as money
         * rather than as two amounts that happen to add up. If this ever fails,
         * a mode is paying out of both pockets at once.
         */
        foreach ([CommissionOverrideMode::DeductFromSale, CommissionOverrideMode::DeductFromCommission] as $mode) {
            $world = $this->world($mode);
            $this->sell($world);

            $this->assertSame(
                30000,
                $this->directAmount($world) + $this->overrideAmount($world),
                "{$mode->value}: the seller's 3% is the whole pool",
            );
        }
    }

    public function test_the_row_records_which_mode_produced_it(): void
    {
        /*
         * BR-4. A leader's row showing a 2% rate against a 10,000 sale and an
         * amount of 6 does not check out — unless the row also says the 2% was
         * taken of the seller's commission. The company toggle that would
         * explain it can be changed the next day; the row cannot.
         */
        $world = $this->world(CommissionOverrideMode::DeductFromCommission);
        $this->sell($world);

        $row = CommissionLedger::withoutGlobalScopes()
            ->where('company_id', $world['company']->id)
            ->where('earned_via', CommissionEarnedVia::Override->value)
            ->sole();

        $this->assertSame(CommissionOverrideMode::DeductFromCommission, $row->override_mode_at_time);
    }

    public function test_the_sellers_commission_can_never_go_negative(): void
    {
        /*
         * THE RUNTIME CAP, and why it exists even though the rate is refused at
         * save time (OverrideDeductionGuard). The chain can deepen AFTER a rate
         * was approved — somebody is given a manager — and no configuration
         * check can reach back in time for that.
         *
         * Three managers at 2% of the sale is 600 against a 300 pool. The two
         * nearest the seller are paid, the third is not, and the seller's row
         * is 0 rather than -300.
         */
        $world = $this->world(CommissionOverrideMode::DeductFromSale, managerCount: 3);

        $this->sell($world);

        $this->assertSame(0, $this->directAmount($world));

        $overrides = CommissionLedger::withoutGlobalScopes()
            ->where('company_id', $world['company']->id)
            ->where('earned_via', CommissionEarnedVia::Override->value)
            ->get();

        $this->assertCount(2, $overrides, 'the third manager gets no row at all, never a 0 row');
        $this->assertSame(30000, (int) $overrides->sum('amount_satang'));
    }

    public function test_additive_pays_every_manager_in_the_chain_in_full(): void
    {
        // The control for the cap above: Additive has no pool to exhaust, so a
        // deep chain costs the company more and takes nothing from the seller.
        $world = $this->world(CommissionOverrideMode::Additive, managerCount: 3);

        $this->sell($world);

        $this->assertSame(30000, $this->directAmount($world));
        $this->assertSame(
            60000,
            (int) CommissionLedger::withoutGlobalScopes()
                ->where('company_id', $world['company']->id)
                ->where('earned_via', CommissionEarnedVia::Override->value)
                ->sum('amount_satang'),
            'three managers at 2% of 10,000, all paid by the company',
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /** @return array{company: Company, referral: Referral, agent: User} */
    private function world(CommissionOverrideMode $mode, int $managerCount = 1): array
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Unilevel,
            'commission_override_mode' => $mode,
        ]);

        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        // Built top-down so each manager is created before the person who
        // reports to them; the seller ends up at the bottom of the chain.
        $managerId = null;
        for ($i = 0; $i < $managerCount; $i++) {
            $manager = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $managerId]);
            $this->certify($manager, $company, $tier);
            $managerId = $manager->id;
        }

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $managerId]);
        $this->certify($agent, $company, $tier);

        // 10,000.00 THB, seller 3%, leader 2% — the example the owner read.
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);

        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
            'effective_from' => now()->subDay(),
        ]);

        CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 200,
            'effective_from' => now()->subDay(),
        ]);

        $referral = Referral::create([
            'company_id' => $company->id,
            'client_id' => Client::factory()->create([
                'company_id' => $company->id,
                'referring_agent_id' => $agent->id,
            ])->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => null,
            'preferred_time' => null,
            'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        return compact('company', 'referral', 'agent');
    }

    private function certify(User $user, Company $company, CertTier $tier): void
    {
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }

    /** @param  array{company: Company, referral: Referral, agent: User}  $world */
    private function sell(array $world): void
    {
        $ledger = app(CommissionService::class)->recordForReferral($world['referral']->fresh(['agent', 'product', 'company']));

        $this->assertNotNull($ledger, 'the fixture must produce a commission, or every assertion is vacuous');
    }

    /** @param  array{company: Company, referral: Referral, agent: User}  $world */
    private function directAmount(array $world): int
    {
        return (int) CommissionLedger::withoutGlobalScopes()
            ->where('agent_id', $world['agent']->id)
            ->where('earned_via', CommissionEarnedVia::Direct->value)
            ->sole()
            ->amount_satang;
    }

    /** @param  array{company: Company, referral: Referral, agent: User}  $world */
    private function overrideAmount(array $world): int
    {
        // Scoped to THIS world's company: the "whole pool" test builds two
        // worlds in one database, and an unscoped sum silently added the
        // first one's overrides to the second's — which is a fixture bug that
        // looks exactly like a calculation bug.
        return (int) CommissionLedger::withoutGlobalScopes()
            ->where('company_id', $world['company']->id)
            ->where('earned_via', CommissionEarnedVia::Override->value)
            ->sum('amount_satang');
    }
}
