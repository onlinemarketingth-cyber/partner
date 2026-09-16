<?php

namespace Tests\Feature\Sales;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ภาพรวมธุรกิจ — 2026-09-16.
 *
 * Owner: "ตัวเลขที่จำเป็นต่างๆ สำหรับผู้บริหารในการบริหารการเงิน ยอดขาย
 * สินค้าขายดีต่างๆ".
 *
 * This screen's whole value is that its figures can be trusted enough to make
 * a decision on, so the tests are not "the endpoint returns keys". They are
 * the four ways a report like this lies while looking right:
 *
 *   1. counting different sets in figures that are then subtracted
 *   2. treating an unrecorded cost as a zero, reporting a sale as pure profit
 *   3. quietly absorbing what it cannot measure instead of disclosing it
 *   4. bucketing two figures on two different dates and calling both "September"
 */
class BusinessOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_revenue_counts_paid_orders_in_the_window_and_nothing_else(): void
    {
        [$company, $admin] = $this->world();

        $this->sale($company, revenue: 500000, paidAt: '2026-09-05');
        $this->sale($company, revenue: 300000, paidAt: '2026-09-20');
        // Outside the window.
        $this->sale($company, revenue: 999900, paidAt: '2026-08-31');
        // Never paid.
        $this->sale($company, revenue: 777700, paidAt: null);

        $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.money.revenue_satang', 800000)
            ->assertJsonPath('data.money.orders_paid', 2);
    }

    public function test_the_commission_it_subtracts_belongs_to_the_sales_it_counted(): void
    {
        /*
         * THE ONE THAT MATTERS. Revenue, commission and cost are subtracted
         * from each other on this screen, so all three have to describe the
         * SAME orders.
         *
         * A ledger row's own paid_at is when the AGENT was transferred — weeks
         * after the sale, and in a different month. Bucketing commission on
         * that axis (which the existing dashboard does, correctly, for a
         * different question) would subtract one month's payouts from another
         * month's revenue and call the difference profit.
         */
        [$company, $admin] = $this->world();

        $septemberSale = $this->sale($company, revenue: 500000, paidAt: '2026-09-05');
        $this->commissionOn($septemberSale, 50000, paidAt: '2026-10-15');

        $augustSale = $this->sale($company, revenue: 400000, paidAt: '2026-08-05');
        // Disbursed IN September, earned on an August sale. Must not appear.
        $this->commissionOn($augustSale, 40000, paidAt: '2026-09-15');

        $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.money.revenue_satang', 500000)
            ->assertJsonPath('data.money.commission_satang', 50000);
    }

    public function test_it_divides_the_two_numbers_that_were_never_divided(): void
    {
        // "ค่าแนะนำต่อยอดขาย" — both operands have sat on one screen for
        // months with nothing computing the ratio.
        [$company, $admin] = $this->world();
        $sale = $this->sale($company, revenue: 1000000, paidAt: '2026-09-05');
        $this->commissionOn($sale, 116000);

        $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.money.commission_ratio', 11.6);
    }

    public function test_a_ratio_over_no_sales_is_null_rather_than_zero(): void
    {
        // 0% commission on nothing is not a fact about the business, and a
        // zero printed in that slot reads as one.
        [, $admin] = $this->world();

        $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.money.commission_ratio', null)
            ->assertJsonPath('data.money.gross_margin_ratio', null)
            ->assertJsonPath('data.money.average_order_satang', null);
    }

    public function test_gross_profit_ignores_sales_it_cannot_cost_rather_than_calling_them_free(): void
    {
        /*
         * THE SECOND ONE THAT MATTERS. Every product in this system today has
         * no cost. If an absent cost counted as 0, the whole back catalogue
         * would report as 100% margin — a confident, wrong, very attractive
         * number.
         *
         * So the uncosted order is excluded from BOTH sides: it is not in the
         * cost, and its revenue is not in the margin either. 400,000 revenue −
         * 250,000 cost − 40,000 commission = 110,000 on 400,000 = 27.5%.
         */
        [$company, $admin] = $this->world();

        $costed = $this->sale($company, revenue: 400000, paidAt: '2026-09-05', costSatang: 250000);
        $this->commissionOn($costed, 40000);

        $uncosted = $this->sale($company, revenue: 600000, paidAt: '2026-09-06', costSatang: null);
        $this->commissionOn($uncosted, 60000);

        $body = $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->json('data');

        // Revenue is still the full truth — both sales happened.
        $this->assertSame(1000000, $body['money']['revenue_satang']);
        $this->assertSame(250000, $body['money']['cost_satang']);
        $this->assertSame(110000, $body['money']['gross_profit_satang']);
        $this->assertSame(27.5, $body['money']['gross_margin_ratio']);
        // ...and the screen is told how much it could not measure.
        $this->assertSame(1, $body['disclosures']['orders_without_cost']);
    }

    public function test_it_discloses_a_refund_the_gateway_reported_but_nobody_actioned(): void
    {
        /*
         * GatewayPaymentService deliberately does NOT flip the status on a
         * webhook refund, so that a webhook cannot claw back an agent's
         * commission. The money is gone and the order still counts as revenue.
         *
         * Subtracting it silently would overrule that decision from a report;
         * hiding it would leave a wrong total with nothing to explain it. So
         * the figure keeps it and says so.
         */
        [$company, $admin] = $this->world();

        $this->sale($company, revenue: 500000, paidAt: '2026-09-05');
        $refunded = $this->sale($company, revenue: 300000, paidAt: '2026-09-06');
        $refunded->forceFill(['refund_reported_at' => Carbon::parse('2026-09-10')])->save();

        $body = $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->json('data');

        $this->assertSame(800000, $body['money']['revenue_satang'], 'still counted');
        $this->assertSame(1, $body['disclosures']['orders_with_reported_refund'], 'and disclosed');
    }

    public function test_it_discloses_deals_that_closed_with_no_money_row(): void
    {
        // Real business the revenue figure cannot see. Without the count, an
        // owner comparing deals closed against revenue has no explanation for
        // the gap.
        [$company, $admin] = $this->world();
        $this->sale($company, revenue: 500000, paidAt: '2026-09-05');

        $orphan = $this->referral($company);
        $orphan->forceFill([
            'current_stage' => PipelineStage::CompletePayment,
            'updated_at' => Carbon::parse('2026-09-07'),
        ])->save();

        $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.disclosures.closed_deals_without_paid_order', 1);
    }

    public function test_every_month_in_the_window_appears_even_the_empty_ones(): void
    {
        /*
         * A chart that omits a month with no sales draws a straight line from
         * August to October, which reads as continuity rather than as a month
         * where nothing happened.
         */
        [$company, $admin] = $this->world();
        $this->sale($company, revenue: 500000, paidAt: '2026-07-10');
        $this->sale($company, revenue: 700000, paidAt: '2026-09-10');

        $monthly = $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-07-01&date_to=2026-09-30')
            ->assertOk()
            ->json('data.monthly');

        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($monthly, 'month'));
        $this->assertSame([500000, 0, 700000], array_column($monthly, 'revenue_satang'));
    }

    public function test_best_sellers_come_from_orders_not_from_closed_deals_times_todays_price(): void
    {
        /*
         * The existing "มุมมองสินค้า" screen counts referrals that reached
         * Complete Payment and multiplies by the product's CURRENT price. Two
         * consequences it cannot avoid: a closed deal with no order counts as
         * a sale, and raising the price rewrites last year's revenue.
         *
         * Here the product's price is changed AFTER the sale, and the reported
         * revenue must not move.
         */
        [$company, $admin] = $this->world();
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 300000]);

        $this->sale($company, revenue: 300000, paidAt: '2026-09-05', product: $product);
        $this->sale($company, revenue: 300000, paidAt: '2026-09-06', product: $product);

        // The price doubles today. The sales above were made at the old one.
        $product->forceFill(['price_satang' => 600000])->save();

        $top = $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->json('data.top_products');

        $this->assertSame(600000, $top[0]['revenue_satang']);
        $this->assertSame(2, $top[0]['units']);
    }

    public function test_a_product_nobody_costed_reports_no_cost_rather_than_a_free_one(): void
    {
        [$company, $admin] = $this->world();
        $this->sale($company, revenue: 300000, paidAt: '2026-09-05', costSatang: null);

        $top = $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->json('data.top_products');

        $this->assertNull($top[0]['cost_satang']);
        $this->assertSame(1, $top[0]['uncosted_units']);
    }

    public function test_agents_are_ranked_by_what_they_sold_not_by_what_they_were_paid(): void
    {
        // The existing dashboard ranks by commission already disbursed, which
        // moves when payouts are run rather than when sales happen.
        [$company, $admin] = $this->world();
        $big = User::factory()->agent()->create(['company_id' => $company->id, 'first_name' => 'ขายเก่ง', 'last_name' => 'มาก']);
        $small = User::factory()->agent()->create(['company_id' => $company->id, 'first_name' => 'ขายน้อย', 'last_name' => 'กว่า']);

        $this->sale($company, revenue: 900000, paidAt: '2026-09-05', agent: $big);
        $this->sale($company, revenue: 100000, paidAt: '2026-09-06', agent: $small);

        $top = $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->json('data.top_agents');

        $this->assertSame($big->id, $top[0]['agent_id']);
        $this->assertSame(900000, $top[0]['revenue_satang']);
    }

    public function test_the_commission_pipeline_is_a_balance_and_ignores_the_window(): void
    {
        /*
         * "What do we owe right now" has no September version. Filtering it by
         * the window would make the figure shrink as somebody narrowed the
         * date range, which reads as the debt going away.
         */
        [$company, $admin] = $this->world();
        $oldSale = $this->sale($company, revenue: 500000, paidAt: '2023-01-05');
        $this->commissionOn($oldSale, 50000, status: PaymentStatus::Pending);

        $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.commission_pipeline.unpaid_satang', 50000);
    }

    public function test_another_companys_money_never_appears(): void
    {
        // BR-6. These are query-builder calls, so TenantScope does not reach
        // them and every one carries its own company predicate.
        [$company, $admin] = $this->world();
        [$other] = $this->world();

        $this->sale($company, revenue: 500000, paidAt: '2026-09-05');
        $this->sale($other, revenue: 999900, paidAt: '2026-09-05');

        $this->actingAs($admin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.money.revenue_satang', 500000);
    }

    public function test_a_super_admins_header_company_narrows_it_the_same_way(): void
    {
        [$company] = $this->world();
        [$other] = $this->world();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->sale($company, revenue: 500000, paidAt: '2026-09-05');
        $this->sale($other, revenue: 300000, paidAt: '2026-09-05');

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/business-overview?company_id={$company->id}&date_from=2026-09-01&date_to=2026-09-30")
            ->assertOk()
            ->assertJsonPath('data.money.revenue_satang', 500000);

        // No company_id is the deliberate read-across.
        $this->actingAs($superAdmin)
            ->getJson('/api/v1/business-overview?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.money.revenue_satang', 800000);
    }

    public function test_it_names_the_date_column_it_used(): void
    {
        // The axis is the one thing a reader cannot infer from the numbers,
        // and four existing reports use four different ones.
        [, $admin] = $this->world();

        $this->actingAs($admin)
            ->getJson('/api/v1/business-overview')
            ->assertOk()
            ->assertJsonPath('data.window.axis', 'orders.paid_at');
    }

    public function test_an_agent_is_refused(): void
    {
        [$company] = $this->world();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/business-overview')->assertForbidden();
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson('/api/v1/business-overview')->assertUnauthorized();
    }

    // ── World ────────────────────────────────────────────────────────────

    /** @return array{0: Company, 1: User} */
    private function world(): array
    {
        $company = Company::factory()->create();

        return [$company, User::factory()->companyAdmin()->create(['company_id' => $company->id])];
    }

    private function referral(Company $company, ?User $agent = null, ?Product $product = null): Referral
    {
        $agent ??= User::factory()->agent()->create(['company_id' => $company->id]);
        $product ??= Product::factory()->create(['company_id' => $company->id]);

        return Referral::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'client_id' => Client::factory()->create(['company_id' => $company->id])->id,
            'product_id' => $product->id,
            'current_stage' => PipelineStage::CompleteRegistered,
            'submitted_at' => now(),
        ]);
    }

    private function sale(
        Company $company,
        int $revenue,
        ?string $paidAt,
        ?int $costSatang = 100,
        ?User $agent = null,
        ?Product $product = null,
    ): Order {
        $referral = $this->referral($company, $agent, $product);

        return Order::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'referral_id' => $referral->id,
            'client_id' => $referral->client_id,
            'agent_id' => $referral->agent_id,
            'product_id' => $referral->product_id,
            'order_number' => 'ORD-'.uniqid(),
            'public_token' => bin2hex(random_bytes(16)),
            'amount_satang' => $revenue,
            'cost_satang_at_time' => $costSatang,
            'payment_method' => 'bank_transfer',
            'status' => $paidAt === null ? OrderStatus::Pending : OrderStatus::Paid,
            'paid_at' => $paidAt === null ? null : Carbon::parse($paidAt),
        ]);
    }

    private function commissionOn(Order $order, int $satang, ?string $paidAt = null, PaymentStatus $status = PaymentStatus::Paid): CommissionLedger
    {
        return CommissionLedger::withoutGlobalScopes()->create([
            'company_id' => $order->company_id,
            'agent_id' => $order->agent_id,
            'referral_id' => $order->referral_id,
            'product_id' => $order->product_id,
            'rate_type_applied' => 'percentage',
            'rate_applied' => 1000,
            'amount_satang' => $satang,
            'payment_status' => $status,
            'paid_at' => $paidAt === null ? null : Carbon::parse($paidAt),
        ]);
    }
}
