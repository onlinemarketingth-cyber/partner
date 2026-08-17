<?php

namespace Tests\Feature\Sales;

use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Models\AgentTarget;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Order;
use App\Models\PipelineStageLog;
use App\Models\Referral;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// TASK-053 / ADR-016 Phase 2 — personal home hub aggregation (/me/home,
// /me/tasks). All figures are the agent's OWN data.
//
// TASK-180 §2 (A1/A2) — the two numbers on this screen that used to answer
// TASK-179's questions their own way now have assertions that fail if the
// old answers are restored. Read MeService's docblock before changing an
// expectation here.
class MeHomeTest extends TestCase
{
    use RefreshDatabase;

    /** A referral for $agent, optionally with the stage log a real advance would have written. */
    private function referral(
        Company $company,
        User $agent,
        PipelineStage $stage,
        bool $withPaymentLog = false,
        ?Client $client = null,
        ?Carbon $closedAt = null,
    ): Referral {
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
                'changed_at' => $closedAt ?? now(),
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

    private function salesTarget(Company $company, User $agent, int $targetSatang): void
    {
        AgentTarget::create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'period' => now()->format('Y-m'),
            'metric' => 'sales_satang',
            'target_value' => $targetSatang,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function homeFor(User $agent): array
    {
        return $this->actingAs($agent)->getJson('/api/v1/me/home')->assertOk()->json('data');
    }

    public function test_home_aggregates_goal_actual_task_counts_and_unread(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // Admin-set sales target for this month.
        $this->salesTarget($company, $agent, 100000);

        // The customer paid 40,000 satang this month → actual 40% of target.
        $closed = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        $this->paidOrder($closed, 40000);

        // One open deal + one unread notification.
        $this->referral($company, $agent, PipelineStage::CompleteRegistered);
        app(NotificationService::class)->notify($agent, NotificationType::System, 'ทดสอบ');

        $data = $this->homeFor($agent);

        $this->assertSame($agent->id, $data['profile']['id']);
        $this->assertSame(0, $data['gamification']['badges_count']);

        $goal = collect($data['goals'])->firstWhere('metric', 'sales_satang');
        $this->assertSame(40000, $goal['actual_value']);
        $this->assertIsInt($goal['actual_value']); // BR-3 — integer satang
        $this->assertSame(100000, $goal['target_value']);
        $this->assertEqualsWithDelta(40.0, $goal['progress'], 0.01);

        $this->assertSame(1, $data['task_counts']['open_deals']);
        $this->assertSame(0, $data['closed_deals_without_order_this_month']);
        $this->assertSame(1, $data['unread_notifications']);
    }

    public function test_tasks_lists_open_deals(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        Referral::factory()->create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'current_stage' => PipelineStage::WaitingAppointment,
        ]);
        // A closed deal must NOT appear as an open task.
        Referral::factory()->create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'current_stage' => PipelineStage::CompletePayment,
        ]);

        $data = $this->actingAs($agent)->getJson('/api/v1/me/tasks')->assertOk()->json('data');

        $this->assertCount(1, $data['open_deals']);
        $this->assertSame('Waiting Appointment', $data['open_deals'][0]['stage_label']);
    }

    public function test_home_is_personal_only(): void
    {
        $company = Company::factory()->create();
        $me = User::factory()->agent()->create(['company_id' => $company->id]);
        $other = User::factory()->agent()->create(['company_id' => $company->id]);
        app(NotificationService::class)->notify($other, NotificationType::System, 'ของคนอื่น');

        $data = $this->homeFor($me);

        // The other agent's unread notification must not count toward mine.
        $this->assertSame(0, $data['unread_notifications']);
    }

    /**
     * TASK-180 A1 — THE assertion. A deal the customer has already paid for
     * and that has been advanced into a post-sale stage (จัดส่ง /
     * นัดใช้บริการ / ติดตามผล, ADR-026) is NOT work the agent still has to
     * do. The old `whereNotIn(current_stage, [complete_payment,
     * ongoing_next_meeting])` handed all three of them back as open tasks,
     * on both the list and the badge beside it.
     */
    public function test_open_deals_exclude_every_stage_at_or_past_complete_payment(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // The only genuinely open one.
        $this->referral($company, $agent, PipelineStage::WaitingAppointment);

        // Closed, in five different shapes — all of them reached payment.
        foreach ([
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
            PipelineStage::Delivery,
            PipelineStage::ServiceAppointment,
            PipelineStage::FollowUp,
        ] as $stage) {
            $this->referral($company, $agent, $stage, withPaymentLog: true);
        }

        $home = $this->homeFor($agent);
        $tasks = $this->actingAs($agent)->getJson('/api/v1/me/tasks')->assertOk()->json('data');

        $this->assertSame(1, $home['task_counts']['open_deals']);
        $this->assertCount(1, $tasks['open_deals']);
        $this->assertSame('waiting_appointment', $tasks['open_deals'][0]['stage_key']);
    }

    /**
     * The other half of ClosedDealPredicate, inverted: a referral parked at
     * a post-sale stage with NO stage log (a fixture / hand-written row) is
     * still not open — `current_stage` alone does not say it reached
     * payment, so applyOpen() must keep it, and the one below at
     * complete_payment must go. This pins the exact complement, not an
     * approximation of it.
     */
    public function test_open_deals_follow_the_shared_predicate_for_log_less_rows(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // No log, and current_stage is not complete_payment → not closed by
        // the shared predicate, so it is open. (TASK-180 §5 records this
        // asymmetry as deliberate and out of scope to widen.)
        $this->referral($company, $agent, PipelineStage::Delivery, withPaymentLog: false);
        // No log either, but parked exactly at payment → closed.
        $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: false);

        $this->assertSame(1, $this->homeFor($agent)['task_counts']['open_deals']);
    }

    /**
     * TASK-180 §6 (A2) — a customer who paid but whose commission is still
     * pending MOVES the progress bar. Under the old ledger source the agent
     * saw 0% until the company ran payroll, and the number they saw was the
     * ledger's `sale_price_satang_at_time` snapshot, which is why the
     * pending row below carries a deliberately different amount: a service
     * that reads it cannot pass.
     */
    public function test_the_target_bar_moves_when_the_customer_pays_not_when_payroll_runs(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->salesTarget($company, $agent, 1000000);

        $closed = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        $this->paidOrder($closed, 250000);

        // Commission for that same sale has NOT been disbursed yet.
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $closed->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Pending,
            'amount_satang' => 7500,
            'sale_price_satang_at_time' => 999999, // must NOT be what ยอดขาย reports
            'paid_at' => null,
        ]);

        $data = $this->homeFor($agent);
        $goal = collect($data['goals'])->firstWhere('metric', 'sales_satang');

        $this->assertSame(250000, $goal['actual_value']);
        $this->assertEqualsWithDelta(25.0, $goal['progress'], 0.01);
        // The disclosure must stay silent — this deal DID produce a paid order.
        $this->assertSame(0, $data['closed_deals_without_order_this_month']);
    }

    /**
     * D3 — month-to-date buckets on the SALE date. A sale collected last
     * month whose commission is disbursed this month belongs to last month
     * and must not inflate this month's bar; the reverse (sold this month,
     * commission still unpaid) must count.
     */
    public function test_month_to_date_buckets_on_the_sale_date_not_the_payout_date(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->salesTarget($company, $agent, 1000000);

        $lastMonth = now()->startOfMonth()->subMonth()->addDays(3);

        $old = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true, closedAt: $lastMonth);
        $this->paidOrder($old, 800000, $lastMonth);
        // ...whose commission is disbursed THIS month.
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $old->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Paid,
            'amount_satang' => 24000,
            'sale_price_satang_at_time' => 800000,
            'paid_at' => now(),
        ]);

        $fresh = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        $this->paidOrder($fresh, 90000);

        $data = $this->homeFor($agent);
        $goal = collect($data['goals'])->firstWhere('metric', 'sales_satang');

        $this->assertSame(90000, $goal['actual_value']);
        // ...and the last-month deal is not disclosed as missing either —
        // it has a paid order, and it is not this month's business.
        $this->assertSame(0, $data['closed_deals_without_order_this_month']);
    }

    /**
     * TASK-180 §2 (A2) disclosure — a deal closed this month with no paid
     * order contributes zero baht and SAYS SO. Never estimated, never
     * folded into actual_value.
     */
    public function test_a_deal_closed_this_month_with_no_paid_order_is_disclosed(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->salesTarget($company, $agent, 500000);

        $paid = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        $this->paidOrder($paid, 120000);

        // Closed this month, but the money was never collected through an
        // order — e.g. no commission rule was configured, or the order was
        // cancelled.
        $this->referral($company, $agent, PipelineStage::Delivery, withPaymentLog: true);

        // Closed this month with an order that has NOT been paid — an
        // unpaid order is not customer money.
        $unpaid = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true);
        Order::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $unpaid->id,
            'client_id' => $unpaid->client_id,
            'agent_id' => $unpaid->agent_id,
            'product_id' => $unpaid->product_id,
            'amount_satang' => 777000,
        ]);

        // Closed LAST month without an order — not this month's shortfall.
        $this->referral(
            $company,
            $agent,
            PipelineStage::CompletePayment,
            withPaymentLog: true,
            closedAt: now()->startOfMonth()->subDays(2),
        );

        $data = $this->homeFor($agent);
        $goal = collect($data['goals'])->firstWhere('metric', 'sales_satang');

        $this->assertSame(120000, $goal['actual_value']);
        $this->assertSame(2, $data['closed_deals_without_order_this_month']);
    }

    /**
     * A2 — the deal and client counters ride the same axis as the money.
     * Two orders against one deal count that deal once.
     */
    public function test_deal_and_client_actuals_come_from_the_same_paid_orders_as_the_money(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        foreach (['deals', 'clients'] as $metric) {
            AgentTarget::create([
                'company_id' => $company->id,
                'agent_id' => $agent->id,
                'period' => now()->format('Y-m'),
                'metric' => $metric,
                'target_value' => 10,
            ]);
        }

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $one = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true, client: $client);
        $two = $this->referral($company, $agent, PipelineStage::CompletePayment, withPaymentLog: true, client: $client);

        // Two paid orders on ONE deal — the deal counts once.
        $this->paidOrder($one, 10000);
        $this->paidOrder($one, 20000);
        $this->paidOrder($two, 30000);

        $goals = collect($this->homeFor($agent)['goals'])->keyBy('metric');

        $this->assertSame(2, $goals['deals']['actual_value']);
        $this->assertSame(1, $goals['clients']['actual_value']);
    }

    /**
     * BR-6 / §5 — every figure on this screen is scoped to the agent's OWN
     * company, not merely to their user id.
     *
     * The scenario is real, not hypothetical: Super Admins can move a user
     * between companies (TASK-011 / "Move user between companies"), and the
     * referrals and orders they made under the old tenant keep that tenant's
     * company_id. Those rows must not follow them into their new company's
     * numbers. Both companies hold data of every kind the service reads, so
     * this cannot pass by both sides being empty — deleting any one of the
     * three company_id filters in MeService changes one of these numbers.
     */
    public function test_home_figures_are_scoped_to_the_agents_own_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        // The agent now belongs to company A.
        $agent = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $this->salesTarget($companyA, $agent, 1000000);

        // Company A: one open deal, one closed deal that produced ฿700, one
        // closed deal with no order at all.
        $this->referral($companyA, $agent, PipelineStage::WaitingAppointment);
        $this->paidOrder($this->referral($companyA, $agent, PipelineStage::CompletePayment, withPaymentLog: true), 70000);
        $this->referral($companyA, $agent, PipelineStage::CompletePayment, withPaymentLog: true);

        // Company B: the same agent id on every row, left behind by the move.
        $clientB = Client::factory()->create(['company_id' => $companyB->id, 'referring_agent_id' => $agent->id]);
        Referral::factory()->create([
            'company_id' => $companyB->id,
            'client_id' => $clientB->id,
            'agent_id' => $agent->id,
            'current_stage' => PipelineStage::WaitingAppointment,
        ]);
        $closedB = $this->referral($companyB, $agent, PipelineStage::CompletePayment, withPaymentLog: true, client: $clientB);
        $this->paidOrder($closedB, 5000000);
        $this->referral($companyB, $agent, PipelineStage::CompletePayment, withPaymentLog: true, client: $clientB);

        $data = $this->homeFor($agent);
        $goal = collect($data['goals'])->firstWhere('metric', 'sales_satang');

        $this->assertSame(1, $data['task_counts']['open_deals']);
        $this->assertSame(70000, $goal['actual_value']);
        $this->assertSame(1, $data['closed_deals_without_order_this_month']);

        $tasks = $this->actingAs($agent)->getJson('/api/v1/me/tasks')->assertOk()->json('data');
        $this->assertCount(1, $tasks['open_deals']);
    }
}
