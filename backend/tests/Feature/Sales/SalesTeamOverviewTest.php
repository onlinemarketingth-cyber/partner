<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Order;
use App\Models\PipelineStageLog;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-050 / ADR-014 — "ทีมขาย" leadership cockpit. Per-agent client
// count + deals-by-stage + conversion, company-scoped, Admin-only.
class SalesTeamOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function referralFor(User $agent, Company $company, PipelineStage $stage, ?Client $client = null): Referral
    {
        $client ??= Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        return Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'current_stage' => $stage,
        ]);
    }

    /**
     * A referral that has REACHED Complete Payment and may since have moved
     * on — i.e. it carries the pipeline_stage_log that
     * PipelineService::advance() writes in the same transaction as the
     * stage change (TASK-179 §3.1).
     */
    private function advancedPastPayment(User $agent, Company $company, PipelineStage $nowAt): Referral
    {
        $referral = $this->referralFor($agent, $company, $nowAt);

        PipelineStageLog::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $referral->id,
            'from_stage' => PipelineStage::Finish1stDoctorMeeting,
            'to_stage' => PipelineStage::CompletePayment,
            'changed_by_user_id' => $agent->id,
            'changed_at' => now(),
        ]);

        return $referral;
    }

    /**
     * The customer's payment against a referral — TASK-179 D1/D2's ONE source
     * for "ยอดขาย". Same helper shape AgentDashboardMetricsTest uses, because
     * the two screens now read the same definition from the same table.
     */
    private function paidOrder(Referral $referral, int $amountSatang): Order
    {
        return Order::factory()->paid()->create([
            'company_id' => $referral->company_id,
            'referral_id' => $referral->id,
            'client_id' => $referral->client_id,
            'agent_id' => $referral->agent_id,
            'product_id' => $referral->product_id,
            'amount_satang' => $amountSatang,
            'paid_at' => now(),
        ]);
    }

    public function test_agent_cannot_access_the_team_overview(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/sales-team-overview')->assertForbidden();
    }

    public function test_company_admin_sees_per_agent_client_and_stage_counts(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // One client with two referrals (different stages) + a second client.
        $client1 = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $this->referralFor($agent, $company, PipelineStage::CompleteRegistered, $client1);
        $this->referralFor($agent, $company, PipelineStage::CompletePayment, $client1);
        $this->referralFor($agent, $company, PipelineStage::CompletePayment); // second distinct client

        $response = $this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk();
        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['client_count']); // two distinct clients
        $this->assertSame(3, $row['total_deals']);
        $this->assertSame(1, $row['deals_by_stage']['complete_registered']);
        $this->assertSame(2, $row['deals_by_stage']['complete_payment']);
        $this->assertSame(0, $row['deals_by_stage']['waiting_appointment']);
        $this->assertSame(2, $row['closed_deals']);
        // conversion = closed 2 / total 3 = 66.7
        $this->assertEqualsWithDelta(66.7, $row['conversion'], 0.1);
    }

    public function test_row_carries_manager_id_for_the_hierarchy_tree(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $member = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $response = $this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk();
        $memberRow = collect($response->json('data'))->firstWhere('agent_id', $member->id);
        $leaderRow = collect($response->json('data'))->firstWhere('agent_id', $leader->id);

        $this->assertSame($leader->id, $memberRow['manager_id']);
        $this->assertNull($leaderRow['manager_id']);
    }

    /**
     * TASK-125 / ADR-025 §1–§2 — the admin-granted `is_team_leader` CAPABILITY
     * travels on every row so the Admin cockpit can split หัวหน้าทีม from
     * ตัวแทนอิสระ. It is asserted here on BOTH a flagged and an unflagged agent
     * precisely because ADR-025 §2 keeps the flag apart from "has direct
     * reports": neither fact may be inferred from the other, so the response
     * has to carry both. The flagged agent below deliberately has NO reports
     * and the unflagged one deliberately HAS one — a service that quietly
     * derived the flag from the tree would fail this test.
     */
    public function test_row_carries_the_admin_granted_team_leader_flag(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // Designated leader who has recruited nobody yet — flag true, 0 reports.
        $flagged = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        // Never designated, but does manage someone — flag false, 1 report.
        $unflagged = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create([
            'company_id' => $company->id,
            'manager_id' => $unflagged->id,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk();
        $rows = collect($response->json('data'));

        $flaggedRow = $rows->firstWhere('agent_id', $flagged->id);
        $unflaggedRow = $rows->firstWhere('agent_id', $unflagged->id);

        $this->assertArrayHasKey('is_team_leader', $flaggedRow);
        $this->assertTrue($flaggedRow['is_team_leader']);
        $this->assertNull($flaggedRow['manager_id']);

        $this->assertArrayHasKey('is_team_leader', $unflaggedRow);
        $this->assertFalse($unflaggedRow['is_team_leader']);
    }

    /**
     * TASK-179 (D1/D2) — the two money figures on a card are two DIFFERENT
     * quantities read from two different tables, and this test pins both:
     *
     *   total_sales_satang       money the CUSTOMER paid → paid ORDERS.
     *   total_commission_satang  money the company DISBURSED → paid LEDGER
     *                            rows.
     *
     * The sale figure used to be `commission_ledger.sale_price_satang_at_time`
     * gated on `payment_status = paid` — the alternative D2 rejected by name
     * — so the fixture below deliberately puts a DIFFERENT number in that
     * column (999999) from the one the order carries. An implementation that
     * goes back to the ledger snapshot reports 999999 and fails here.
     */
    public function test_row_carries_email_phone_and_the_two_money_axes(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'email' => 'seller@example.test',
            'phone' => '0812223333',
        ]);

        // A real closed sale: the customer paid 100000 satang (the order),
        // and the company has since disbursed 3000 satang of commission.
        $referral = $this->referralFor($agent, $company, PipelineStage::CompletePayment);
        $this->paidOrder($referral, 100000);
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'referral_id' => $referral->id,
            'payment_status' => PaymentStatus::Paid,
            'amount_satang' => 3000,
            // Deliberately NOT 100000: the sale figure must not come from here.
            'sale_price_satang_at_time' => 999999,
        ]);

        // Pending ledger row — excluded from the COMMISSION figure (that one
        // really is about disbursement) and irrelevant to the sale figure.
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Pending,
            'amount_satang' => 9999,
            'sale_price_satang_at_time' => 50000,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk();
        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);

        $this->assertSame('seller@example.test', $row['agent_email']);
        $this->assertSame('0812223333', $row['agent_phone']);
        $this->assertSame(3000, $row['total_commission_satang']); // disbursed, paid only
        $this->assertSame(100000, $row['total_sales_satang']);    // D1 — the customer's money
        $this->assertIsInt($row['total_sales_satang']);           // BR-3
        // Every closed deal here has a paid order, so nothing is undisclosed.
        $this->assertSame(0, $row['closed_deals_without_order']);
    }

    /**
     * TASK-179 (D1) — the defect this test exists for: the sale figure was
     * gated on the AGENT'S COMMISSION having been paid out, so a customer
     * who had paid in full contributed ฿0 to "ยอดขาย" until payroll ran.
     * Two different axes; only one of them is about the customer.
     */
    public function test_a_customer_who_paid_counts_even_while_the_commission_is_still_pending(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $referral = $this->referralFor($agent, $company, PipelineStage::CompletePayment);
        $this->paidOrder($referral, 890000);
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'referral_id' => $referral->id,
            'payment_status' => PaymentStatus::Pending,
            'amount_satang' => 26700,
            'sale_price_satang_at_time' => 890000,
            'paid_at' => null,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk();
        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);

        // The customer's full payment, in the month they paid it.
        $this->assertSame(890000, $row['total_sales_satang']);
        // ...and nothing disbursed yet, which is a separate, honest zero.
        $this->assertSame(0, $row['total_commission_satang']);
        $this->assertSame(0, $row['closed_deals_without_order']);
    }

    /**
     * TASK-179 §3.2 (D2) — a closed deal with NO paid order contributes zero
     * baht and is DISCLOSED rather than estimated. The old implementation
     * `COALESCE(sale_price_satang_at_time, 0)`-ed such a deal to zero with no
     * disclosure at all, which is exactly how the total under-reported
     * silently. This is the per-agent twin of the dashboard's field.
     */
    public function test_a_closed_deal_with_no_paid_order_contributes_zero_and_is_disclosed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // 1. A normal closed sale — counted.
        $counted = $this->referralFor($agent, $company, PipelineStage::CompletePayment);
        $this->paidOrder($counted, 890000);

        // 2. Closed, but no order at all (closed before ADR-017 existed, or
        //    no commission rule was configured so no ledger row was ever
        //    written either) → 0 baht, disclosed.
        $this->referralFor($agent, $company, PipelineStage::CompletePayment);

        // 3. Closed with an order that is NOT paid — an unpaid order is not
        //    customer money, so this is disclosed too.
        $unpaid = $this->referralFor($agent, $company, PipelineStage::CompletePayment);
        Order::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $unpaid->id,
            'client_id' => $unpaid->client_id,
            'agent_id' => $unpaid->agent_id,
            'product_id' => $unpaid->product_id,
            'amount_satang' => 777000,
        ]);

        // 4. An OPEN deal with no order is not "closed without an order" and
        //    must not inflate the disclosure.
        $this->referralFor($agent, $company, PipelineStage::WaitingAppointment);

        $response = $this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk();
        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);

        // Only the paid order's money — the missing ones are never estimated.
        $this->assertSame(890000, $row['total_sales_satang']);
        $this->assertSame(4, $row['total_deals']);
        $this->assertSame(3, $row['closed_deals']);
        $this->assertSame(2, $row['closed_deals_without_order']);
    }

    /**
     * TASK-179 (D2/D4) — the disclosure follows the SAME "closed" definition
     * as everything else (ClosedDealPredicate): a deal advanced past Complete
     * Payment into a post-sale stage is still closed, so if it has no paid
     * order it is still disclosed. A second predicate that looked only at
     * `current_stage = complete_payment` would report 0 here.
     */
    public function test_the_disclosure_counts_deals_advanced_past_payment_too(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->advancedPastPayment($agent, $company, PipelineStage::FollowUp);

        $response = $this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk();
        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);

        $this->assertSame(1, $row['closed_deals']);
        $this->assertSame(0, $row['total_sales_satang']);
        $this->assertSame(1, $row['closed_deals_without_order']);
    }

    /**
     * BR-6 — deliberately NOT an empty-vs-empty assertion. Company B has a
     * BIGGER paid order than company A, so a service that dropped the
     * company filter on its agent list would both add B's row and change A's
     * numbers; every assertion below would fail, not just the row check.
     */
    public function test_sales_money_is_tenant_isolated(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);

        $this->paidOrder($this->referralFor($agentA, $companyA, PipelineStage::CompletePayment), 100000);
        $this->paidOrder($this->referralFor($agentB, $companyB, PipelineStage::CompletePayment), 5000000);
        // ...and an undisclosed-zero deal in B, so B's disclosure count is
        // non-zero too and cannot leak in as a coincidental 0.
        $this->referralFor($agentB, $companyB, PipelineStage::CompletePayment);

        $rows = collect($this->actingAs($adminA)->getJson('/api/v1/sales-team-overview')->assertOk()->json('data'));

        $this->assertNull($rows->firstWhere('agent_id', $agentB->id));
        $this->assertSame(100000, $rows->firstWhere('agent_id', $agentA->id)['total_sales_satang']);
        // The whole visible company, not just the one row: 5,000,000 satang
        // of company B money must be nowhere in this response.
        $this->assertSame(100000, $rows->sum('total_sales_satang'));
        $this->assertSame(0, $rows->sum('closed_deals_without_order'));
    }

    public function test_team_overview_is_tenant_isolated(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);
        $this->referralFor($agentB, $companyB, PipelineStage::CompletePayment);

        $response = $this->actingAs($adminA)->getJson('/api/v1/sales-team-overview')->assertOk();

        $this->assertNull(collect($response->json('data'))->firstWhere('agent_id', $agentB->id));
    }

    /**
     * TASK-179 §3.1 / D4 — the per-agent aggregate now uses the SAME
     * ClosedDealPredicate as the dashboard, so a deal advanced past
     * Complete Payment into a post-sale stage is still closed. Before this
     * it was a hardcoded two-stage list here and another one there, and
     * the two drifted (F-3).
     */
    public function test_deals_advanced_into_post_sale_stages_are_still_closed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // Every one of these passed THROUGH Complete Payment, so each
        // carries the pipeline_stage_log a real PipelineService::advance()
        // would have written — that log is what makes "has it closed?"
        // answerable once the referral has moved on to a later stage.
        $this->advancedPastPayment($agent, $company, PipelineStage::CompletePayment);
        $this->advancedPastPayment($agent, $company, PipelineStage::OngoingNextMeeting);
        $this->advancedPastPayment($agent, $company, PipelineStage::Delivery);
        $this->advancedPastPayment($agent, $company, PipelineStage::ServiceAppointment);
        $this->advancedPastPayment($agent, $company, PipelineStage::FollowUp);
        $this->referralFor($agent, $company, PipelineStage::WaitingAppointment);

        $response = $this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk();
        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);

        $this->assertSame(6, $row['total_deals']);
        $this->assertSame(5, $row['closed_deals']);
    }

    /**
     * TASK-179 §3.6 (F-15) — the header KPI must read a true company-level
     * COUNT(DISTINCT client_id), not the sum of the per-agent cards. One
     * client referred by two agents counts ONCE for the company and once
     * per agent — TASK-049 exists because that is a real scenario here.
     */
    public function test_company_level_clients_total_does_not_double_count_a_shared_client(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);

        $shared = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentA->id]);
        $this->referralFor($agentA, $company, PipelineStage::CompleteRegistered, $shared);
        $this->referralFor($agentB, $company, PipelineStage::CompleteRegistered, $shared);
        // One more client, agent A only.
        $this->referralFor($agentA, $company, PipelineStage::CompleteRegistered);

        $response = $this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk();
        $rows = collect($response->json('data'));

        // Summing the cards gives 3 — that is the defect.
        $this->assertSame(3, $rows->sum('client_count'));
        // The company actually has 2 distinct clients.
        $this->assertSame(2, $response->json('meta.clients_total'));
    }

    public function test_clients_total_is_tenant_isolated(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);

        $this->referralFor($agentA, $companyA, PipelineStage::CompleteRegistered);
        $this->referralFor($agentB, $companyB, PipelineStage::CompleteRegistered);
        $this->referralFor($agentB, $companyB, PipelineStage::CompleteRegistered);

        $response = $this->actingAs($adminA)->getJson('/api/v1/sales-team-overview')->assertOk();

        $this->assertSame(1, $response->json('meta.clients_total'));
    }

    public function test_client_list_can_be_filtered_by_agent_for_drilldown(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agentX = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentY = User::factory()->agent()->create(['company_id' => $company->id]);

        $clientX = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentX->id]);
        $this->referralFor($agentX, $company, PipelineStage::CompleteRegistered, $clientX);
        $clientY = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentY->id]);
        $this->referralFor($agentY, $company, PipelineStage::CompleteRegistered, $clientY);

        $this->actingAs($admin)->getJson('/api/v1/clients?agent_id='.$agentX->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $clientX->id);
    }

    /**
     * TASK-203 — buildWithTotals() has ALWAYS returned pending/rejected
     * agents alongside approved ones (no status filter on the base query);
     * what was missing was any way for the "ทีมขาย" cockpit to tell them
     * apart. This locks down that the five new fields on the row carry the
     * EXACT SAME values GET /agent-approvals (UserResource) reports for the
     * same user — fetched from both endpoints and compared, so a future
     * drift between the two fails here rather than silently reaching the
     * frontend's shared approvalSourceChip()/approvalProvenance() rendering.
     */
    public function test_row_carries_the_same_approval_provenance_as_the_agent_approvals_endpoint(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);

        $pending = User::factory()->agent()->create([
            'company_id' => $company->id,
            'agent_approval_status' => \App\Enums\AgentApprovalStatus::Pending,
        ]);
        $adminApproved = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/agent-approvals/{$adminApproved->id}/approve")->assertOk();

        $rejected = User::factory()->agent()->create([
            'company_id' => $company->id,
            'agent_approval_status' => \App\Enums\AgentApprovalStatus::Pending,
        ]);
        $this->actingAs($admin)->putJson("/api/v1/agent-approvals/{$rejected->id}/reject", ['reason' => 'เอกสารไม่ครบ'])->assertOk();

        // Leader-approved: acting as the leader through the same endpoint.
        $leaderApproved = User::factory()->agent()->create([
            'company_id' => $company->id,
            'manager_id' => $leader->id,
            'agent_approval_status' => \App\Enums\AgentApprovalStatus::Pending,
        ]);
        $this->actingAs($leader)->putJson("/api/v1/agent-approvals/{$leaderApproved->id}/approve")->assertOk();

        $approvalsPending = collect($this->actingAs($admin)->getJson('/api/v1/agent-approvals?status=pending')->assertOk()->json('data'));
        $approvalsApproved = collect($this->actingAs($admin)->getJson('/api/v1/agent-approvals?status=approved')->assertOk()->json('data'));
        $approvalsRejected = collect($this->actingAs($admin)->getJson('/api/v1/agent-approvals?status=rejected')->assertOk()->json('data'));

        $teamRows = collect($this->actingAs($admin)->getJson('/api/v1/sales-team-overview')->assertOk()->json('data'));

        foreach ([
            [$pending->id, $approvalsPending],
            [$adminApproved->id, $approvalsApproved],
            [$rejected->id, $approvalsRejected],
            [$leaderApproved->id, $approvalsApproved],
        ] as [$userId, $fromApprovalsEndpoint]) {
            $expected = $fromApprovalsEndpoint->firstWhere('id', $userId);
            $actual = $teamRows->firstWhere('agent_id', $userId);

            $this->assertNotNull($expected, "user {$userId} missing from /agent-approvals fixture");
            $this->assertNotNull($actual, "user {$userId} missing from /sales-team-overview");

            $this->assertSame($expected['agent_approval_status'], $actual['agent_approval_status']);
            $this->assertSame($expected['approval_rejection_reason'], $actual['approval_rejection_reason']);
            $this->assertSame($expected['approval_source'], $actual['approval_source']);
            $this->assertSame($expected['approved_by'], $actual['approved_by']);
            $this->assertSame($expected['approved_at'], $actual['approved_at']);
        }

        // Pinned literal, not just cross-endpoint equality — makes sure both
        // endpoints agree with reality and not merely with each other.
        $this->assertSame('pending', $teamRows->firstWhere('agent_id', $pending->id)['agent_approval_status']);
        $this->assertSame('rejected', $teamRows->firstWhere('agent_id', $rejected->id)['agent_approval_status']);
        $this->assertSame('เอกสารไม่ครบ', $teamRows->firstWhere('agent_id', $rejected->id)['approval_rejection_reason']);
        $this->assertSame('team_leader', $teamRows->firstWhere('agent_id', $leaderApproved->id)['approval_source']);
        $this->assertSame($leader->id, $teamRows->firstWhere('agent_id', $leaderApproved->id)['approved_by']['id']);
    }

    /**
     * BR-6 — the new approval fields must not become a second, unscoped
     * leak. Reuses the same asymmetric-fixture shape as
     * test_sales_money_is_tenant_isolated: company B's pending agent must be
     * wholly absent from company A's admin response.
     */
    public function test_approval_fields_are_tenant_isolated(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $pendingInB = User::factory()->agent()->create([
            'company_id' => $companyB->id,
            'agent_approval_status' => \App\Enums\AgentApprovalStatus::Pending,
        ]);

        $rows = collect($this->actingAs($adminA)->getJson('/api/v1/sales-team-overview')->assertOk()->json('data'));

        $this->assertNull($rows->firstWhere('agent_id', $pendingInB->id));
    }
}
