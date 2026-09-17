<?php

namespace Tests\Feature\Supplier;

use App\Enums\PaymentStatus;
use App\Enums\SupplierGpMode;
use App\Enums\WithdrawalSource;
use App\Enums\WithdrawalStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplierSettlementLedger;
use App\Models\SupplierWithdrawalRequest;
use App\Models\User;
use App\Services\Supplier\SupplierPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 2026-09-16 — paying a supplier, and the tax taken off on the way.
 *
 * Two themes, and they are the two places this differs from the commission
 * payout flow it otherwise copies:
 *
 *   1. NEGATIVE ROWS. The owner ruled a supplier carries the shortfall where
 *      commission and GP exceed the sale price. That only works if the
 *      negative row is carried INTO the payout and closed with it — left
 *      behind, it silently docks every future payout by the same amount, for
 *      ever, and no screen would show why the numbers keep coming up short.
 *
 *   2. WITHHOLDING TAX, which this system has never had anywhere. The trap is
 *      `gross × rate` on a payout spanning goods (withheld at nothing) and
 *      services (withheld at a rate) — there is no single rate, and whichever
 *      one gets picked is wrong for part of the money every time.
 */
class SupplierPayoutTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    private Company $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::factory()->create([
            'is_supplier' => true,
            'supplier_gp_mode' => SupplierGpMode::PercentOfSale->value,
            'supplier_gp_value' => 3000,
            'supplier_release_trigger' => 'on_payment',
            'payment_bank_name' => 'ธนาคารกสิกรไทย',
            'payment_bank_account_number' => '123-4-56789-0',
            'payment_bank_account_name' => 'บริษัท ซัพพลายเออร์ จำกัด',
        ]);

        $this->seller = Company::factory()->create();
    }

    /**
     * A settlement row, written straight rather than through a sale — this
     * file is about what happens AFTER the arithmetic, which
     * SupplierSettlementTest already covers.
     */
    private function ledgerRow(int $amount, ?int $whtRate = null, bool $released = true): SupplierSettlementLedger
    {
        $product = Product::factory()->create([
            'company_id' => null,
            'supplier_company_id' => $this->supplier->id,
        ]);

        $order = Order::factory()->create([
            'company_id' => $this->seller->id,
            'product_id' => $product->id,
            'amount_satang' => max(1, $amount),
        ]);

        return SupplierSettlementLedger::create([
            'supplier_company_id' => $this->supplier->id,
            'company_id' => $this->seller->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sale_price_satang_at_time' => max(1, $amount),
            'commission_satang_at_time' => 0,
            'gp_mode_at_time' => SupplierGpMode::PercentOfSale->value,
            'gp_value_at_time' => 3000,
            'gp_satang_at_time' => 0,
            'wht_rate_at_time' => $whtRate,
            'amount_satang' => $amount,
            'released_at' => $released ? now() : null,
            'payment_status' => PaymentStatus::Pending->value,
        ]);
    }

    private function service(): SupplierPayoutService
    {
        return app(SupplierPayoutService::class);
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    // ── The balance ────────────────────────────────────────────────────

    public function test_the_balance_separates_payable_from_not_yet_released(): void
    {
        /*
         * Three numbers rather than one, because "you are owed X" invites "no,
         * you owe me more" — and the difference is almost always money whose
         * release trigger has not fired.
         */
        $this->ledgerRow(60000);
        $this->ledgerRow(40000, released: false);

        $balance = $this->service()->balanceFor($this->supplier);

        $this->assertSame(60000, $balance['payable_satang']);
        $this->assertSame(40000, $balance['unreleased_satang']);
        $this->assertSame(0, $balance['reserved_satang']);
    }

    public function test_a_shortfall_nets_off_inside_the_balance(): void
    {
        $this->ledgerRow(100000);
        $this->ledgerRow(-30000);

        $this->assertSame(70000, $this->service()->balanceFor($this->supplier)['payable_satang']);
    }

    public function test_raising_a_payout_reserves_the_rows_so_they_cannot_be_paid_twice(): void
    {
        $this->ledgerRow(60000);

        $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $balance = $this->service()->balanceFor($this->supplier);
        $this->assertSame(0, $balance['payable_satang']);
        $this->assertSame(60000, $balance['reserved_satang']);
    }

    // ── Negative rows ──────────────────────────────────────────────────

    public function test_a_payout_carries_the_negative_rows_with_it_and_closes_them(): void
    {
        /*
         * THE BUG THIS PREVENTS: pay the positive rows, leave the negative one
         * behind. The supplier is overpaid today, and the shortfall reappears
         * in the balance tomorrow, and the day after, for ever.
         */
        $good = $this->ledgerRow(100000);
        $bad = $this->ledgerRow(-30000);

        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $this->assertSame(70000, $request->gross_satang);
        $this->assertCount(2, $request->items);

        $this->service()->markTransferred($request, $this->admin(), 'REF-1');

        $this->assertSame(PaymentStatus::Paid, $good->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $bad->fresh()->payment_status, 'the shortfall row was left open');
        $this->assertSame(0, $this->service()->balanceFor($this->supplier)['payable_satang']);
    }

    public function test_a_net_negative_balance_cannot_be_paid_and_is_not_chased(): void
    {
        // The owner's ruling is that a shortfall nets off against future
        // sales, which is what leaving these rows open achieves. We do not
        // invoice a supplier for it.
        $this->ledgerRow(10000);
        $this->ledgerRow(-40000);

        $this->expectException(ValidationException::class);

        $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);
    }

    // ── Withholding tax ────────────────────────────────────────────────

    public function test_no_rate_means_no_tax_and_net_equals_gross(): void
    {
        // Correct for a sale of goods, and the state every deal starts in.
        $this->ledgerRow(100000, whtRate: null);

        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $this->assertSame(0, $request->wht_satang);
        $this->assertSame(100000, $request->net_satang);
    }

    public function test_a_single_rate_is_withheld_and_recorded(): void
    {
        $this->ledgerRow(100000, whtRate: 300); // 3%

        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $this->assertSame(300, $request->wht_rate_at_time);
        $this->assertSame(3000, $request->wht_satang);
        $this->assertSame(97000, $request->net_satang);
    }

    public function test_a_mixed_payout_withholds_per_rate_and_never_an_average(): void
    {
        /*
         * ฿1,000 of goods at 0% and ฿1,000 of services at 3%.
         *
         * Correct answer: ฿30 — withheld on the services only.
         * The average-rate bug would compute 1.5% of ฿2,000 and arrive at the
         * same ฿30, so the amounts here are deliberately UNEQUAL, where the
         * two answers diverge.
         */
        $this->ledgerRow(300000, whtRate: 0);
        $this->ledgerRow(100000, whtRate: 300);

        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $this->assertSame(3000, $request->wht_satang, 'tax was not grouped by rate');
        $this->assertSame(400000, $request->gross_satang);
        $this->assertSame(397000, $request->net_satang);
        // NULL means "several rates applied", never "no tax" — which is 0.
        $this->assertNull($request->wht_rate_at_time);
    }

    public function test_tax_is_not_withheld_against_a_shortfall(): void
    {
        /*
         * A negative row is not income and cannot have tax deducted from it.
         * Including it would reduce the tax withheld on the sales that ARE
         * income — a real under-deduction, not a rounding choice.
         */
        $this->ledgerRow(100000, whtRate: 300);
        $this->ledgerRow(-50000, whtRate: 300);

        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $this->assertSame(50000, $request->gross_satang);
        $this->assertSame(3000, $request->wht_satang, 'tax should be 3% of the 100000 of income, not of the 50000 net');
        $this->assertSame(47000, $request->net_satang);
    }

    public function test_gross_minus_wht_equals_net_on_every_request(): void
    {
        $this->ledgerRow(123457, whtRate: 300);
        $this->ledgerRow(98765, whtRate: 0);

        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $this->assertSame($request->gross_satang - $request->wht_satang, $request->net_satang);
    }

    // ── The state machine ──────────────────────────────────────────────

    public function test_an_admin_raised_payout_opens_already_approved(): void
    {
        // The act of raising it IS the decision — asking the same person to
        // approve it on the next screen is the rubber stamp WithdrawalSource
        // warns about.
        $this->ledgerRow(60000);

        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $this->assertSame(WithdrawalStatus::Approved, $request->status);
    }

    public function test_a_supplier_raised_request_waits_for_review(): void
    {
        $this->ledgerRow(60000);

        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::AgentRequest);

        $this->assertSame(WithdrawalStatus::PendingReview, $request->status);
    }

    public function test_the_ledger_settles_at_transfer_and_not_at_approval(): void
    {
        // แนวทาง C, unchanged from the commission flow: an approval is a
        // decision, a transfer is an event, and the books follow the event.
        $row = $this->ledgerRow(60000);
        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $this->assertSame(PaymentStatus::Pending, $row->fresh()->payment_status);

        $this->service()->markTransferred($request, $this->admin(), 'REF-9');

        $this->assertSame(PaymentStatus::Paid, $row->fresh()->payment_status);
    }

    public function test_the_bank_details_are_snapshotted_so_a_later_change_cannot_rewrite_history(): void
    {
        $this->ledgerRow(60000);
        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);

        $this->supplier->forceFill(['payment_bank_account_number' => '999-9-99999-9'])->save();

        $this->assertSame('123-4-56789-0', $request->fresh()->bank_account_number);
    }

    public function test_rejecting_returns_the_rows_to_the_payable_pool(): void
    {
        $this->ledgerRow(60000);
        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::AgentRequest);

        $this->service()->reject($request, $this->admin(), 'เอกสารไม่ครบ');

        $this->assertSame(60000, $this->service()->balanceFor($this->supplier)['payable_satang']);
    }

    public function test_the_minimum_binds_a_supplier_asking_but_not_us_settling(): void
    {
        /*
         * Refusing to let somebody ask for 12 baht is a policy. Refusing to
         * let ourselves pay a debt we have decided to pay is not. Same
         * asymmetry the agent flow has.
         */
        $this->supplier->forceFill(['supplier_min_withdrawal_satang' => 100000])->save();
        $this->ledgerRow(50000);

        $paidAnyway = $this->service()->open($this->supplier->fresh(), $this->admin(), WithdrawalSource::CompanyPayout);
        $this->assertSame(50000, $paidAnyway->gross_satang);

        $this->service()->reject($paidAnyway, $this->admin(), 'reset');

        $this->expectException(ValidationException::class);
        $this->service()->open($this->supplier->fresh(), $this->admin(), WithdrawalSource::AgentRequest);
    }

    public function test_unreleased_rows_are_never_paid_out(): void
    {
        $this->ledgerRow(60000, released: false);

        $this->expectException(ValidationException::class);

        $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);
    }

    public function test_transfer_is_refused_on_a_request_that_was_never_approved(): void
    {
        $this->ledgerRow(60000);
        $request = $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::AgentRequest);

        $this->expectException(ValidationException::class);

        $this->service()->markTransferred($request, $this->admin(), 'REF-2');
    }

    public function test_a_supplier_with_nothing_owed_cannot_have_a_payout_raised(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->open($this->supplier, $this->admin(), WithdrawalSource::CompanyPayout);
    }

    public function test_one_suppliers_rows_never_reach_another_suppliers_payout(): void
    {
        $other = Company::factory()->create(['is_supplier' => true]);
        $this->ledgerRow(60000);

        $this->assertSame(0, $this->service()->balanceFor($other)['payable_satang']);
        $this->assertSame(0, SupplierWithdrawalRequest::where('supplier_company_id', $other->id)->count());
    }
}
