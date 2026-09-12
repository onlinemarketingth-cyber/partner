<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Enums\PromotionStatus;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductPricePromotion;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Commission\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-12 (owner: "ทำแผน PV กับการตั้งค่าแบบคอม ขายตรง เก็บการคิดแบบ %
 * และ Fix จำนวนเงิน ไว้กับค่าคอมปรกติ").
 *
 * PV changes the ONE number every payout in this system is computed from,
 * into a table nobody may correct afterwards (BR-4). So the tests that
 * matter here are not "does PV work" — they are the four ways it could
 * quietly be wrong:
 *
 *   1. it changes a price-basis company's amounts (it must not, at all)
 *   2. it lets a promotion move the commission (the whole point is that
 *      it does not)
 *   3. it pays 0 when a product simply has no PV yet (a default of 0
 *      would have; see the pv_satang migration)
 *   4. it writes a row whose own arithmetic no longer checks out
 */
class CommissionPointValueBasisTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_price_basis_company_is_computed_exactly_as_before(): void
    {
        /*
         * THE REGRESSION GUARD, and the reason this whole change is safe to
         * put in front of live companies. Every existing company is on
         * 'price'. If this ever fails, PV has leaked into a company that
         * never opted into it, and the failure is money.
         *
         * The product is deliberately given a PV as well — a price-basis
         * company must ignore it even when it is there, because a company
         * may well fill PV in while evaluating the switch.
         */
        $world = $this->world(basis: CommissionBasis::Price, priceSatang: 890000, pvSatang: 100000, rateBasisPoints: 1000);

        $ledger = $this->sell($world);

        // 10% of 8,900.00 THB, not of the 1,000.00 PV.
        $this->assertSame(89000, $ledger->amount_satang);
        $this->assertSame(890000, $ledger->sale_price_satang_at_time);
        $this->assertSame(890000, $ledger->commission_base_satang_at_time);
        $this->assertSame(CommissionBasis::Price, $ledger->commission_basis_at_time);
    }

    public function test_a_pv_company_pays_on_the_point_value_not_the_price(): void
    {
        $world = $this->world(basis: CommissionBasis::PointValue, priceSatang: 890000, pvSatang: 100000, rateBasisPoints: 1000);

        $ledger = $this->sell($world);

        // 10% of 1,000.00 PV.
        $this->assertSame(10000, $ledger->amount_satang);
        // And the row still records what the CUSTOMER paid, which is a
        // different fact and stays in its own column.
        $this->assertSame(890000, $ledger->sale_price_satang_at_time);
        $this->assertSame(100000, $ledger->commission_base_satang_at_time);
        $this->assertSame(CommissionBasis::PointValue, $ledger->commission_basis_at_time);
    }

    public function test_a_promotion_moves_the_price_and_leaves_pv_alone(): void
    {
        /*
         * THE REASON A COMPANY ADOPTS PV AT ALL.
         *
         * On the price basis, a 50%-off week halves every agent's
         * commission on that product — which is why discounting is a fight
         * with the sales force. On PV it does not, and the agent is paid
         * the same for the same sale whatever marketing decided that week.
         *
         * Both halves are asserted in one test on purpose: "the promotion
         * applied" and "the commission did not move" only mean something
         * together. Asserted separately, a bug that stopped promotions
         * resolving entirely would pass the second half happily.
         */
        $world = $this->world(basis: CommissionBasis::PointValue, priceSatang: 890000, pvSatang: 100000, rateBasisPoints: 1000);

        ProductPricePromotion::withoutGlobalScopes()->create([
            'company_id' => $world['company']->id,
            'product_id' => $world['product']->id,
            'discounted_price_satang' => 445000,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $ledger = $this->sell($world);

        $this->assertSame(445000, $ledger->sale_price_satang_at_time, 'the customer really did pay the discounted price');
        $this->assertSame(10000, $ledger->amount_satang, 'and the agent was paid off PV, unchanged by the discount');
        $this->assertSame(100000, $ledger->commission_base_satang_at_time);
    }

    public function test_a_product_with_no_pv_falls_back_to_the_price_rather_than_paying_nothing(): void
    {
        /*
         * The gap that a `default(0)` column would have turned into a
         * silent 0-satang payout: every gate passes, a row is written, and
         * the first anybody hears of it is an agent asking where their
         * money went — about a row BR-4 forbids correcting.
         *
         * Falling back to the price pays what the company was paying the
         * day before it flipped the switch, which is the one answer nobody
         * can be surprised by. CommissionReadinessTest is where the
         * loudness lives.
         */
        $world = $this->world(basis: CommissionBasis::PointValue, priceSatang: 890000, pvSatang: null, rateBasisPoints: 1000);

        $ledger = $this->sell($world);

        $this->assertSame(89000, $ledger->amount_satang);
        $this->assertSame(890000, $ledger->commission_base_satang_at_time);
        // Still stamped 'pv': the company IS a PV company, and a row that
        // claimed otherwise would hide the gap the moment it was paid out.
        $this->assertSame(CommissionBasis::PointValue, $ledger->commission_basis_at_time);
    }

    public function test_zero_pv_is_a_decision_and_is_honoured(): void
    {
        // `??` and not `?:` — the difference between "this bundled item
        // pays nobody, deliberately" and "nobody has filled this in".
        $world = $this->world(basis: CommissionBasis::PointValue, priceSatang: 890000, pvSatang: 0, rateBasisPoints: 1000);

        $ledger = $this->sell($world);

        $this->assertSame(0, $ledger->amount_satang);
        $this->assertSame(0, $ledger->commission_base_satang_at_time);
    }

    public function test_a_fixed_amount_rate_is_the_same_money_on_either_basis(): void
    {
        /*
         * The owner's actual sentence: "เก็บการคิดแบบ % และ Fix จำนวนเงิน
         * ไว้กับค่าคอมปรกติ". A fixed 500 baht is 500 baht — the basis is
         * an input to ONE of the two formulas, not a replacement for
         * either, and CommissionRateCalculator ignores the base entirely
         * for FixedSatang. Asserted rather than assumed, because "PV
         * company" is exactly the kind of global switch somebody later
         * wires into both branches.
         */
        $onPrice = $this->sell($this->world(
            basis: CommissionBasis::Price, priceSatang: 890000, pvSatang: 100000,
            rateBasisPoints: 50000, rateType: CommissionRateType::FixedSatang,
        ));

        $onPv = $this->sell($this->world(
            basis: CommissionBasis::PointValue, priceSatang: 890000, pvSatang: 100000,
            rateBasisPoints: 50000, rateType: CommissionRateType::FixedSatang,
        ));

        $this->assertSame(50000, $onPrice->amount_satang);
        $this->assertSame(50000, $onPv->amount_satang);
    }

    public function test_the_row_explains_its_own_amount(): void
    {
        /*
         * BR-4's real requirement, stated as arithmetic: a ledger row may
         * never be edited, so the row's own columns are the only surviving
         * explanation of the number in amount_satang. A year from now the
         * product's PV will have moved and the company's basis may have
         * flipped; if the row cannot be re-derived from itself, it cannot
         * be re-derived at all.
         */
        $ledger = $this->sell($this->world(
            basis: CommissionBasis::PointValue, priceSatang: 890000, pvSatang: 123400, rateBasisPoints: 750,
        ));

        $recomputed = (int) round($ledger->commission_base_satang_at_time * $ledger->rate_applied / 10000);

        $this->assertSame($ledger->amount_satang, $recomputed);
    }

    public function test_a_reversal_carries_the_same_basis_as_the_row_it_reverses(): void
    {
        /*
         * A reversal is only meaningful as HALF OF A PAIR: the two rows have
         * to describe the same sale, priced the same way, or nothing about
         * them reconciles. Before PV that was automatic — there was only one
         * base. Now the basis is a column, and a reversal that dropped it
         * would carry a price and a rate whose product is not its own amount,
         * which is exactly the unreadable row BR-4 makes permanent.
         */
        $ledger = $this->sell($this->world(
            basis: CommissionBasis::PointValue, priceSatang: 890000, pvSatang: 100000, rateBasisPoints: 1000,
        ));

        $reversal = CommissionLedger::withoutGlobalScopes()->create([
            'company_id' => $ledger->company_id,
            'agent_id' => $ledger->agent_id,
            'referral_id' => $ledger->referral_id,
            'product_id' => $ledger->product_id,
            'sale_price_satang_at_time' => $ledger->sale_price_satang_at_time,
            'commission_basis_at_time' => $ledger->commission_basis_at_time,
            'commission_base_satang_at_time' => $ledger->commission_base_satang_at_time,
            'rate_type_applied' => $ledger->rate_type_applied,
            'rate_applied' => $ledger->rate_applied,
            'amount_satang' => -$ledger->amount_satang,
            'earned_via' => CommissionEarnedVia::Reversal,
            'payment_status' => PaymentStatus::Pending,
            'reverses_commission_ledger_id' => $ledger->id,
        ]);

        $this->assertSame(CommissionBasis::PointValue, $reversal->commission_basis_at_time);
        $this->assertSame($ledger->commission_base_satang_at_time, $reversal->commission_base_satang_at_time);
        $this->assertSame(0, $ledger->amount_satang + $reversal->amount_satang);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /** @return array{company: Company, product: Product, referral: Referral} */
    private function world(
        CommissionBasis $basis,
        int $priceSatang,
        ?int $pvSatang,
        int $rateBasisPoints,
        CommissionRateType $rateType = CommissionRateType::Percentage,
    ): array {
        $company = Company::factory()->create([
            'commission_basis' => $basis,
            // Unilevel with no manager above the agent: exactly one ledger
            // row per sale, so every assertion above is about the direct
            // commission and nothing else.
            'commission_plan_type' => CommissionPlanType::Unilevel,
        ]);

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => null]);

        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        $product = Product::factory()->create([
            'company_id' => $company->id,
            'price_satang' => $priceSatang,
            'pv_satang' => $pvSatang,
        ]);

        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => $rateType,
            'rate_value' => $rateBasisPoints,
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

        return ['company' => $company, 'product' => $product, 'referral' => $referral];
    }

    /** @param  array{company: Company, product: Product, referral: Referral}  $world */
    private function sell(array $world): CommissionLedger
    {
        $ledger = app(CommissionService::class)->recordForReferral($world['referral']->fresh(['agent', 'product', 'company']));

        $this->assertNotNull($ledger, 'the fixture must actually produce a commission, or every assertion below is vacuous');

        return $ledger->fresh();
    }
}
