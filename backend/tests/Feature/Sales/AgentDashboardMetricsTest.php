<?php

namespace Tests\Feature\Sales;

use App\Enums\AgentApprovalStatus;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Order;
use App\Models\PipelineStageLog;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// TASK-052 / ADR-015 — chart-based Agent Dashboard metrics endpoint.
//
// TASK-179 §3.9 — every one of the human decisions D1–D4 has an assertion
// here that fails if the definition is reverted. Read the docblock on
// AgentDashboardMetricsService before changing an expectation: each of
// these numbers used to be a real figure under a label describing a
// different quantity.
class AgentDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    /** A referral for $agent, optionally with the stage log a real advance would have written. */
    private function referral(Company $company, User $agent, PipelineStage $stage, bool $withPaymentLog = false, ?Client $client = null): Referral
    {
        $client ??= Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'current_stage' => $stage,
        ]);

        if ($withPaymentLog) {
            PipelineStageLog::factory()->create([
                'company_id' => $company->id,
                'referral_id' => $referral->id,
                'from_stage' => PipelineStage::Finish1stDoctorMeeting,
                'to_stage' => PipelineStage::CompletePayment,
                'changed_by_user_id' => $agent->id,
                'changed_at' => now(),
            ]);
        }

        return $referral;
    }

    private function paidOrder(Referral $referral, int $amountSatang, ?Carbon $paidAt = null): Order
    {
        return Order::factory()->paid()->create([
            'company_id' => $referral->company_id,
            'referral_id' => $referral->id,
            'client_id' => $referral->client_id,
            'agent_id' => $referral->agent_id,
            'product_id' => $referral->product_id,
            'amount_satang' => $amountSatang,
            'paid_at' => $paidAt ?? now(),
        ]);
    }

    private function metricsFor(User $admin): array
    {
        return $this->actingAs($admin)->getJson('/api/v1/agent-dashboard-metrics')->assertOk()->json('data');
    }

    public function test_agent_cannot_access_the_dashboard_metrics(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/agent-dashboard-metrics')->assertForbidden();
    }

    public function test_totals_funnel_and_money(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $this->referral($company, $agent, PipelineStage::CompleteRegistered, client: $client);
        $closed = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true, client: $client);

        // D1/D2 — the customer paid 200,000 satang for the closed deal.
        $this->paidOrder($closed, 200000);

        // Paid commission — a DIFFERENT axis, and deliberately a different
        // number so a service that confused the two cannot pass.
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $closed->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Paid,
            'amount_satang' => 6000,
            'sale_price_satang_at_time' => 999999, // must NOT be what ยอดขาย reports
            'paid_at' => now(),
        ]);
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $closed->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Pending,
            'amount_satang' => 1111,
        ]);

        $data = $this->metricsFor($admin);

        $this->assertSame(1, $data['totals']['agents_total']);
        $this->assertSame(2, $data['totals']['deals_total']);
        $this->assertSame(1, $data['totals']['deals_closed']);
        $this->assertEqualsWithDelta(50.0, $data['totals']['conversion'], 0.01);

        // D1/D2 — money the CUSTOMER paid, from orders. NOT 999999.
        $this->assertSame(200000, $data['totals']['sales_paid_satang']);
        $this->assertIsInt($data['totals']['sales_paid_satang']); // BR-3
        $this->assertSame(0, $data['totals']['closed_deals_without_order']);

        $this->assertSame(6000, $data['totals']['commission_paid_satang']);
        $this->assertSame(1111, $data['totals']['commission_pending_satang']);

        // Funnel carries the WHOLE stage vocabulary, post-sale stages included.
        $this->assertSame(1, $data['deals_by_stage']['complete_registered']);
        $this->assertSame(1, $data['deals_by_stage']['complete_payment']);
        $this->assertArrayHasKey('waiting_appointment', $data['deals_by_stage']);
        $this->assertArrayHasKey('follow_up', $data['deals_by_stage']);

        // 6-month series present; current month carries the SALE.
        $this->assertCount(6, $data['monthly']);
        $current = collect($data['monthly'])->firstWhere('month', now()->format('Y-m'));
        $this->assertSame(200000, $current['sales_satang']);
        $this->assertSame(6000, $current['commission_satang']);

        $this->assertSame($agent->id, $data['top_agents'][0]['agent_id']);
        $this->assertSame(6000, $data['top_agents'][0]['commission_satang']);
    }

    /**
     * §3.9 bullet 1 (D2) — a closed deal with NO paid order contributes
     * zero baht AND is disclosed. The disclosure is the whole point: without
     * it the headline silently under-reports and nobody can tell.
     */
    public function test_a_closed_deal_with_no_order_contributes_no_money_and_is_disclosed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $withOrder = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        $this->paidOrder($withOrder, 150000);

        // Closed, but the sale was never collected through an order — e.g.
        // it closed before ADR-017 existed, or the order was cancelled.
        $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);

        // A closed deal whose only order is NOT paid still counts as
        // "without order" — an unpaid order is not customer money.
        $unpaid = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        Order::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $unpaid->id,
            'client_id' => $unpaid->client_id,
            'agent_id' => $unpaid->agent_id,
            'product_id' => $unpaid->product_id,
            'amount_satang' => 777000,
        ]);

        $totals = $this->metricsFor($admin)['totals'];

        $this->assertSame(3, $totals['deals_closed']);
        // Only the one paid order — the other two are NEVER estimated.
        $this->assertSame(150000, $totals['sales_paid_satang']);
        $this->assertSame(2, $totals['closed_deals_without_order']);
    }

    /**
     * §3.9 bullet 2 (D4) — THE assertion that would have caught F-3.
     * Advancing a paid deal into a post-sale stage must never reduce the
     * close rate.
     */
    public function test_a_referral_advanced_past_complete_payment_is_still_closed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // All four are past payment: one parked at it, one on the medical
        // self-loop, two on post-sale stages ADR-026 added.
        $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        $this->referral($company, $agent, PipelineStage::OngoingNextMeeting, withPaymentLog: true);
        $this->referral($company, $agent, PipelineStage::Delivery, withPaymentLog: true);
        $this->referral($company, $agent, PipelineStage::FollowUp, withPaymentLog: true);
        // Not closed — never reached payment.
        $this->referral($company, $agent, PipelineStage::WaitingAppointment);

        $totals = $this->metricsFor($admin)['totals'];

        $this->assertSame(5, $totals['deals_total']);
        $this->assertSame(4, $totals['deals_closed']);
        $this->assertEqualsWithDelta(80.0, $totals['conversion'], 0.01);
    }

    /**
     * §3.1 — the OTHER half of the predicate. A referral whose stage was
     * written directly (no advance, hence no log) must still count. This is
     * why the predicate is a union and not "log only".
     */
    public function test_a_referral_at_complete_payment_with_no_stage_log_still_counts_as_closed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: false);

        $this->assertSame(0, DB::table('pipeline_stage_logs')->count());
        $this->assertSame(1, $this->metricsFor($admin)['totals']['deals_closed']);
    }

    /**
     * §3.9 bullet 3 (D3, F-6) — a sale in month A whose commission is
     * disbursed in month B lands in month A. Before this the sale figure
     * was read off the ledger row, so it followed the disbursement.
     */
    public function test_a_sale_lands_in_the_month_it_was_sold_not_the_month_commission_was_paid(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $saleMonth = now()->startOfMonth()->subMonths(2);
        $payoutMonth = now()->startOfMonth();

        $referral = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        $this->paidOrder($referral, 500000, $saleMonth->copy()->addDays(3));

        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $referral->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Paid,
            'amount_satang' => 15000,
            'sale_price_satang_at_time' => 500000,
            'paid_at' => $payoutMonth->copy()->addDays(2),
        ]);

        $monthly = collect($this->metricsFor($admin)['monthly']);

        $saleBucket = $monthly->firstWhere('month', $saleMonth->format('Y-m'));
        $payoutBucket = $monthly->firstWhere('month', $payoutMonth->format('Y-m'));

        $this->assertSame(500000, $saleBucket['sales_satang']);
        $this->assertSame(0, $saleBucket['commission_satang']);

        $this->assertSame(0, $payoutBucket['sales_satang']);
        $this->assertSame(15000, $payoutBucket['commission_satang']);
    }

    /**
     * §3.4 (F-7) — the KPI counts exactly what GET /agent-approvals lists:
     * every pending user, any role. A pending Company Admin used to appear
     * in the list and not in the KPI beside it.
     */
    public function test_pending_approvals_kpi_matches_the_agent_approvals_list(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        User::factory()->agent()->create([
            'company_id' => $company->id,
            'agent_approval_status' => AgentApprovalStatus::Pending,
        ]);
        User::factory()->companyAdmin()->create([
            'company_id' => $company->id,
            'agent_approval_status' => AgentApprovalStatus::Pending,
        ]);
        User::factory()->agent()->create([
            'company_id' => $company->id,
            'agent_approval_status' => AgentApprovalStatus::Approved,
        ]);

        $kpi = $this->metricsFor($admin)['totals']['agents_pending'];

        $listTotal = $this->actingAs($admin)->getJson('/api/v1/agent-approvals')
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(2, $kpi);
        $this->assertSame($listTotal, $kpi);
    }

    /**
     * §3.5 (F-8) — agents_total is ACTIVE agents; a deactivated (soft
     * deleted) agent belongs to agents_inactive, not to the headline.
     */
    public function test_agents_total_excludes_deactivated_agents(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        User::factory()->agent()->count(2)->create(['company_id' => $company->id]);
        $gone = User::factory()->agent()->create(['company_id' => $company->id]);
        $gone->delete();

        $totals = $this->metricsFor($admin)['totals'];

        $this->assertSame(2, $totals['agents_total']);
        $this->assertSame(2, $totals['agents_active']);
        $this->assertSame(1, $totals['agents_inactive']);
    }

    /**
     * §3.8 (F-5) — one agent, one slice: their HIGHEST passed tier. The
     * slices must sum to the number of certified agents, not to more.
     */
    public function test_cert_tier_donut_counts_each_agent_once_at_their_highest_tier(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // cert_tiers is platform-wide (no company_id) — see its migration.
        $basic = CertTier::factory()->create(['key' => 'basic', 'sort_order' => 1]);
        $intermediate = CertTier::factory()->create(['key' => 'intermediate', 'sort_order' => 2]);

        $twoTiers = User::factory()->agent()->create(['company_id' => $company->id]);
        $basicOnly = User::factory()->agent()->create(['company_id' => $company->id]);

        foreach ([[$twoTiers, $basic], [$twoTiers, $intermediate], [$basicOnly, $basic]] as [$user, $tier]) {
            DB::table('user_certifications')->insert([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'cert_tier_id' => $tier->id,
                'passed_at' => now(),
                'created_at' => now(),
            ]);
        }

        $data = $this->metricsFor($admin);
        $slices = collect($data['cert_tier_distribution'])->keyBy('key');

        $this->assertSame(1, $slices['basic']['count']);
        $this->assertSame(1, $slices['intermediate']['count']);
        // The partition property: slices sum to the certified agents.
        $this->assertSame(2, collect($data['cert_tier_distribution'])->sum('count'));
        $this->assertSame(2, $data['totals']['cert_passed']);
    }

    /**
     * §3.9 bullet 4 (BR-6) — company A's dashboard never sees company B's
     * orders or referrals.
     */
    public function test_metrics_are_tenant_isolated(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);
        User::factory()->agent()->count(2)->create(['company_id' => $companyB->id]);

        $referralB = $this->referral($companyB, $agentB, PipelineStage::CompletePayment, withPaymentLog: true);
        $this->paidOrder($referralB, 900000);

        $totals = $this->metricsFor($adminA)['totals'];

        $this->assertSame(0, $totals['agents_total']);
        $this->assertSame(0, $totals['deals_total']);
        $this->assertSame(0, $totals['deals_closed']);
        $this->assertSame(0, $totals['sales_paid_satang']);
        $this->assertSame(0, $totals['closed_deals_without_order']);
        $this->assertSame(0, collect($this->metricsFor($adminA)['monthly'])->sum('sales_satang'));
    }

    /**
     * BR-6, the other direction: company A's own numbers must be complete
     * and correct while company B has its own data — a scope test that
     * passes on an empty database proves nothing.
     */
    public function test_each_company_sees_only_its_own_sales(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);

        $this->paidOrder($this->referral($companyA, $agentA, PipelineStage::CompletePayment, withPaymentLog: true), 111000);
        $this->paidOrder($this->referral($companyB, $agentB, PipelineStage::CompletePayment, withPaymentLog: true), 222000);

        $this->assertSame(111000, $this->metricsFor($adminA)['totals']['sales_paid_satang']);
        $this->assertSame(222000, $this->metricsFor($adminB)['totals']['sales_paid_satang']);
    }

    /**
     * A brand-new company must not manufacture figures. Nothing here is a
     * measurement — §4.4 makes the UI say "ยังไม่มีข้อมูล" rather than
     * rendering a confident 0% gauge, and it can only do that if the
     * backend's zeros are genuinely "no rows", not "no query".
     */
    public function test_a_company_with_no_data_reports_zeros_and_empty_series(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $data = $this->metricsFor($admin);

        $this->assertSame(0, $data['totals']['deals_total']);
        $this->assertSame(0, $data['totals']['deals_closed']);
        // 0.0 round-trips through JSON as 0 — compare numerically.
        $this->assertEqualsWithDelta(0.0, $data['totals']['conversion'], 0.001);
        $this->assertSame(0, $data['totals']['sales_paid_satang']);
        $this->assertSame(0, $data['totals']['closed_deals_without_order']);
        $this->assertSame([], $data['cert_tier_distribution']);
        $this->assertSame([], $data['top_agents']);
    }

    /** Guard against a product price leaking back in as the sales figure (D2's rejected alternative). */
    public function test_sales_uses_the_order_amount_not_the_current_product_price(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $referral = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        $this->paidOrder($referral, 890000);

        // The catalogue price changes afterwards — last year's revenue must
        // not move with it.
        Product::whereKey($referral->product_id)->update(['price_satang' => 990000]);

        $this->assertSame(890000, $this->metricsFor($admin)['totals']['sales_paid_satang']);
    }
}
