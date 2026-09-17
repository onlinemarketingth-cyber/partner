<?php

namespace Tests\Feature\Supplier;

use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Enums\SupplierGpMode;
use App\Enums\SupplierReleaseTrigger;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Referral;
use App\Models\Supplier;
use App\Models\SupplierSettlementLedger;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Supplier\SupplierSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026-09-16 — the arithmetic that decides what a supplier is paid.
 *
 * Owner's formula: `ราคาขาย − ค่าคอม − GP`.
 *
 * Every test here is about a way that sum can be wrong while looking right.
 * None of them would fail loudly in production: an under-counted commission
 * just makes a slightly bigger number, a clamped negative just makes a
 * slightly smaller one, and both land in an immutable ledger that BR-4 says
 * nobody may go back and correct. The only place to catch them is here.
 */
class SupplierSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        return $tier;
    }

    /**
     * A supplier with terms set, plus one of our companies selling one of its
     * products.
     *
     * @return array{supplier: Supplier, seller: Company, agent: User, product: Product}
     */
    private function scenario(
        SupplierGpMode $mode = SupplierGpMode::PercentOfSale,
        int $gpValue = 3000,
        int $price = 100000,
        SupplierReleaseTrigger $trigger = SupplierReleaseTrigger::OnPayment,
        ?int $whtRate = null,
        int $commissionRate = 1000,
    ): array {
        $supplier = Supplier::factory()->create([
            'gp_mode' => $mode->value,
            'gp_value' => $gpValue,
            'release_trigger' => $trigger->value,
            'wht_rate' => $whtRate,
        ]);

        $seller = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $seller->id]);
        $tier = $this->passBasicCert($agent, $seller);

        // A PLATFORM product (company_id null) with a supplier — the shape
        // §3.1 of the spec describes and the only one the rules allow.
        $product = Product::factory()->create([
            'company_id' => null,
            'supplier_id' => $supplier->id,
            'price_satang' => $price,
        ]);

        CommissionRule::factory()->create([
            'company_id' => $seller->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $commissionRate,
        ]);

        return compact('supplier', 'seller', 'agent', 'product');
    }

    /** An order already paid, with commission rows written the normal way. */
    private function paidOrder(array $s, int $extraCommissionSatang = 0): Order
    {
        $client = Client::factory()->create([
            'company_id' => $s['seller']->id,
            'referring_agent_id' => $s['agent']->id,
        ]);

        $referral = Referral::create([
            'company_id' => $s['seller']->id,
            'client_id' => $client->id,
            'agent_id' => $s['agent']->id,
            'product_id' => $s['product']->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered,
            'submitted_at' => now(),
        ]);

        $order = Order::factory()->create([
            'company_id' => $s['seller']->id,
            'referral_id' => $referral->id,
            'client_id' => $client->id,
            'agent_id' => $s['agent']->id,
            'product_id' => $s['product']->id,
            'amount_satang' => $s['product']->price_satang,
        ]);

        // The seller's own commission row, as CommissionService would write it.
        CommissionLedger::create([
            'company_id' => $s['seller']->id,
            'agent_id' => $s['agent']->id,
            'referral_id' => $referral->id,
            'product_id' => $s['product']->id,
            'sale_price_satang_at_time' => $order->amount_satang,
            'rate_type_applied' => CommissionRateType::Percentage->value,
            'rate_applied' => 1000,
            'amount_satang' => intdiv($order->amount_satang * 1000, 10000),
        ]);

        /*
         * A SECOND row on the same sale — an upline's override.
         *
         * This is the case the owner's answer turns on ("Full ระบบของเราที่
         * เราทำได้เลย"). An order routinely produces more than one commission
         * row and the obvious implementation counts only the seller's.
         */
        if ($extraCommissionSatang > 0) {
            $upline = User::factory()->agent()->create(['company_id' => $s['seller']->id]);
            CommissionLedger::create([
                'company_id' => $s['seller']->id,
                'agent_id' => $upline->id,
                'referral_id' => $referral->id,
                'product_id' => $s['product']->id,
                'sale_price_satang_at_time' => $order->amount_satang,
                'rate_type_applied' => CommissionRateType::Percentage->value,
                'rate_applied' => 500,
                'amount_satang' => $extraCommissionSatang,
            ]);
        }

        return $order->fresh();
    }

    private function service(): SupplierSettlementService
    {
        return app(SupplierSettlementService::class);
    }

    // ── The formula ────────────────────────────────────────────────────

    public function test_it_records_sale_minus_commission_minus_gp(): void
    {
        // ฿1,000 sale · 10% commission = ฿100 · 30% GP of sale = ฿300
        $s = $this->scenario();
        $order = $this->paidOrder($s);

        $row = $this->service()->recordForOrder($order);

        $this->assertSame(100000, $row->sale_price_satang_at_time);
        $this->assertSame(10000, $row->commission_satang_at_time);
        $this->assertSame(30000, $row->gp_satang_at_time);
        $this->assertSame(60000, $row->amount_satang);
    }

    public function test_commission_counts_every_ledger_row_on_the_sale_not_just_the_sellers(): void
    {
        /*
         * THE MOST EXPENSIVE BUG THIS FILE PREVENTS.
         *
         * Counting only the seller's row overpays the supplier on every sale
         * that has an upline — which is most of them — by exactly the upline's
         * commission, forever, with nothing anywhere reporting it.
         */
        $s = $this->scenario();
        $order = $this->paidOrder($s, extraCommissionSatang: 5000);

        $row = $this->service()->recordForOrder($order);

        $this->assertSame(15000, $row->commission_satang_at_time, 'the upline override was not counted');
        // 100000 − 15000 − 30000
        $this->assertSame(55000, $row->amount_satang);
    }

    // ── The three GP modes ─────────────────────────────────────────────

    public function test_percent_of_sale_takes_the_share_before_commission_is_considered(): void
    {
        $s = $this->scenario(SupplierGpMode::PercentOfSale, 3000);
        $row = $this->service()->recordForOrder($this->paidOrder($s));

        $this->assertSame(30000, $row->gp_satang_at_time);
    }

    public function test_percent_of_net_takes_the_share_of_what_is_left_after_commission(): void
    {
        // (100000 − 10000) × 30% = 27000, not 30000.
        $s = $this->scenario(SupplierGpMode::PercentOfNet, 3000);
        $row = $this->service()->recordForOrder($this->paidOrder($s));

        $this->assertSame(27000, $row->gp_satang_at_time);
        $this->assertSame(63000, $row->amount_satang);
    }

    public function test_fixed_per_unit_ignores_the_price(): void
    {
        $s = $this->scenario(SupplierGpMode::FixedPerUnit, 25000);
        $row = $this->service()->recordForOrder($this->paidOrder($s));

        $this->assertSame(25000, $row->gp_satang_at_time);
    }

    public function test_a_product_may_override_the_deals_gp_and_must_carry_both_halves(): void
    {
        $s = $this->scenario(SupplierGpMode::PercentOfSale, 3000);
        $s['product']->forceFill([
            'supplier_gp_mode' => SupplierGpMode::FixedPerUnit->value,
            'supplier_gp_value' => 12345,
        ])->save();

        $row = $this->service()->recordForOrder($this->paidOrder($s));

        $this->assertSame(12345, $row->gp_satang_at_time);
    }

    public function test_a_product_naming_a_mode_with_no_value_falls_back_whole_not_half(): void
    {
        /*
         * A half-applied override would read the COMPANY's 3000 (basis points)
         * as the PRODUCT's fixed amount — 30 satang instead of 30%. A number
         * 1000x wrong that still looks like a number.
         */
        $s = $this->scenario(SupplierGpMode::PercentOfSale, 3000);
        $s['product']->forceFill([
            'supplier_gp_mode' => SupplierGpMode::FixedPerUnit->value,
            'supplier_gp_value' => null,
        ])->save();

        $row = $this->service()->recordForOrder($this->paidOrder($s));

        $this->assertSame(SupplierGpMode::PercentOfSale, $row->gp_mode_at_time);
        $this->assertSame(30000, $row->gp_satang_at_time);
    }

    // ── BR-7: refusing to invent a number ──────────────────────────────

    public function test_it_refuses_rather_than_defaulting_a_missing_gp(): void
    {
        $s = $this->scenario();
        $s['supplier']->forceFill(['gp_mode' => null, 'gp_value' => null])->save();
        $order = $this->paidOrder($s);

        $this->expectException(RuntimeException::class);

        $this->service()->recordForOrder($order->fresh());
    }

    public function test_it_refuses_rather_than_guessing_when_money_becomes_payable(): void
    {
        $s = $this->scenario();
        $s['supplier']->forceFill(['release_trigger' => null])->save();
        $order = $this->paidOrder($s);

        $this->expectException(RuntimeException::class);

        $this->service()->recordForOrder($order->fresh());
    }

    // ── The supplier carries a shortfall (owner's ruling) ──────────────

    public function test_amount_goes_negative_when_commission_and_gp_exceed_the_price(): void
    {
        /*
         * Owner: "supplier เป็นผู้รับผิดชอบ". A max(0, …) anywhere in the
         * service would move this loss onto us and leave nothing on any screen
         * to say it had happened.
         *
         * ฿1,000 sale · ฿100 + ฿500 upline commission · ฿900 fixed GP
         */
        $s = $this->scenario(SupplierGpMode::FixedPerUnit, 90000);
        $order = $this->paidOrder($s, extraCommissionSatang: 50000);

        $row = $this->service()->recordForOrder($order);

        $this->assertSame(-50000, $row->amount_satang);
    }

    public function test_percent_of_net_never_pays_the_supplier_extra_on_a_loss_making_sale(): void
    {
        // Commission alone exceeds the price, so there is no net to take a
        // share of. A negative base would produce a NEGATIVE GP — i.e. hand
        // the supplier money for the privilege.
        $s = $this->scenario(SupplierGpMode::PercentOfNet, 3000);
        $order = $this->paidOrder($s, extraCommissionSatang: 120000);

        $row = $this->service()->recordForOrder($order);

        $this->assertSame(0, $row->gp_satang_at_time);
        $this->assertSame(-30000, $row->amount_satang);
    }

    // ── The line that keeps the profit report honest ────────────────────

    public function test_it_writes_the_orders_cost_so_gross_profit_comes_out_as_our_gp(): void
    {
        /*
         * BusinessOverviewService computes revenue − cost − commission. With
         * cost = what we hand the supplier, that collapses to exactly our GP.
         * Leave it unwritten and the sale is either dropped from the report or
         * — worse, if somebody later "fixes" that with a zero — counted as
         * pure profit at the full sale price.
         */
        $s = $this->scenario();
        $order = $this->paidOrder($s);

        $row = $this->service()->recordForOrder($order);

        $order->refresh();
        $this->assertSame($row->amount_satang, (int) $order->cost_satang_at_time);

        $grossProfit = $order->amount_satang - $order->cost_satang_at_time - $row->commission_satang_at_time;
        $this->assertSame($row->gp_satang_at_time, $grossProfit);
    }

    // ── Release triggers ───────────────────────────────────────────────

    public function test_on_payment_releases_immediately(): void
    {
        $s = $this->scenario(trigger: SupplierReleaseTrigger::OnPayment);
        $row = $this->service()->recordForOrder($this->paidOrder($s));

        $this->assertNotNull($row->released_at);
    }

    public function test_on_redeemed_writes_the_row_now_and_releases_later(): void
    {
        /*
         * The row must exist from the moment the sale is paid, whatever the
         * trigger. Writing it late would make the payables figure correct only
         * once every outstanding parcel had been delivered.
         */
        $s = $this->scenario(trigger: SupplierReleaseTrigger::OnRedeemed);
        $order = $this->paidOrder($s);

        $row = $this->service()->recordForOrder($order);

        $this->assertNotNull($row->id, 'the row must exist even before it is payable');
        $this->assertNull($row->released_at);

        $this->service()->releaseFor($order, SupplierReleaseTrigger::OnRedeemed);

        $this->assertNotNull($row->fresh()->released_at);
    }

    public function test_releasing_for_the_wrong_trigger_does_nothing(): void
    {
        $s = $this->scenario(trigger: SupplierReleaseTrigger::OnRedeemed);
        $order = $this->paidOrder($s);
        $row = $this->service()->recordForOrder($order);

        $this->service()->releaseFor($order, SupplierReleaseTrigger::OnDelivered);

        $this->assertNull($row->fresh()->released_at);
    }

    public function test_release_is_a_one_way_door(): void
    {
        // A multi-use voucher redeems twice; the second must not move the
        // timestamp, or a supplier's payable date would drift forward every
        // time a customer turned up.
        $s = $this->scenario(trigger: SupplierReleaseTrigger::OnRedeemed);
        $order = $this->paidOrder($s);
        $row = $this->service()->recordForOrder($order);

        $this->service()->releaseFor($order, SupplierReleaseTrigger::OnRedeemed);
        $first = $row->fresh()->released_at;

        $this->travel(5)->minutes();
        $moved = $this->service()->releaseFor($order, SupplierReleaseTrigger::OnRedeemed);

        $this->assertSame(0, $moved);
        $this->assertEquals($first, $row->fresh()->released_at);
    }

    // ── Doing nothing, correctly ───────────────────────────────────────

    public function test_an_ordinary_product_with_no_supplier_records_nothing(): void
    {
        $s = $this->scenario();
        $s['product']->forceFill(['supplier_id' => null])->save();

        $this->assertNull($this->service()->recordForOrder($this->paidOrder($s)));
        $this->assertDatabaseCount('supplier_settlement_ledger', 0);
    }

    public function test_recording_twice_for_one_order_is_a_no_op_not_a_second_payment(): void
    {
        $s = $this->scenario();
        $order = $this->paidOrder($s);

        $first = $this->service()->recordForOrder($order);
        $second = $this->service()->recordForOrder($order->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SupplierSettlementLedger::count());
    }
}
