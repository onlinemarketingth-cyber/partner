<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "รอชำระที่ไหน" — the counts behind the payment screen's tabs.
 *
 * ── WHY THIS ENDPOINT EXISTS (human, 2026-08-22) ──
 *
 * There was nowhere in the platform to ask "who is waiting to pay". The
 * Admin console had no order screen at all; the Agent Portal's list called
 * GET /orders with no filter, so the question was answered by scrolling and
 * reading status chips.
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 * 1. THE COUNT AND THE LIST DISAGREE. The tab says "รอตรวจสลิป 4" and the tab
 *    shows two. An admin works a queue until the number reaches zero, and a
 *    number counted over a wider set never does — they keep looking for work
 *    that is not there. This is why index() and summary() share one
 *    scopedQuery() instead of each writing the same three lines.
 *
 * 2. AN AGENT SEES THE COMPANY'S NUMBERS. The narrowing is one `if` in a
 *    method neither endpoint owns any more. Losing it leaks how much every
 *    other agent is selling — through a counts endpoint, where nobody would
 *    think to look for a data leak.
 *
 * 3. A STATUS VANISHES WHEN ITS QUEUE EMPTIES. Absent-at-zero means the tab
 *    disappears exactly when the admin most wants to see "0 — nothing to do".
 *    A missing tab reads as a broken screen, not as an empty queue.
 *
 * 4. THE MONEY IS WRONG. Totals cannot come from a paginated list: it knows
 *    its own `total` row count but sums only the page. That failure looks
 *    plausible — a smaller number, on a screen full of numbers.
 */
class OrderSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User} company, admin, agent */
    private function companyWithStaff(): array
    {
        $company = Company::factory()->create();

        return [
            $company,
            User::factory()->companyAdmin()->create(['company_id' => $company->id]),
            User::factory()->agent()->create(['company_id' => $company->id]),
        ];
    }

    private function orderFor(Company $company, User $agent, OrderStatus $status, int $amountSatang): Order
    {
        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
        ]);

        return Order::factory()->create([
            'referral_id' => $referral->id,
            'agent_id' => $agent->id,
            'status' => $status,
            'amount_satang' => $amountSatang,
        ]);
    }

    /** @return array<string, array{count: int, total_satang: int}> */
    private function summaryFor(User $actor): array
    {
        $rows = $this->actingAs($actor)
            ->getJson('/api/v1/orders/summary')
            ->assertOk()
            ->json('data');

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']] = ['count' => $row['count'], 'total_satang' => $row['total_satang']];
        }

        return $byStatus;
    }

    public function test_it_counts_and_totals_each_payment_state(): void
    {
        [$company, $admin, $agent] = $this->companyWithStaff();

        $this->orderFor($company, $agent, OrderStatus::Pending, 100000);
        $this->orderFor($company, $agent, OrderStatus::Pending, 250000);
        $this->orderFor($company, $agent, OrderStatus::AwaitingVerification, 890000);
        $this->orderFor($company, $agent, OrderStatus::Paid, 500000);

        $summary = $this->summaryFor($admin);

        $this->assertSame(2, $summary['pending']['count']);
        // The whole set, not one page — and integer satang out (BR-3).
        $this->assertSame(350000, $summary['pending']['total_satang']);
        $this->assertSame(1, $summary['awaiting_verification']['count']);
        $this->assertSame(890000, $summary['awaiting_verification']['total_satang']);
        $this->assertSame(1, $summary['paid']['count']);
    }

    public function test_every_status_is_present_even_at_zero(): void
    {
        // A tab that disappears when its queue empties reads as a broken
        // screen, not as "nothing to do".
        [$company, $admin, $agent] = $this->companyWithStaff();
        $this->orderFor($company, $agent, OrderStatus::Pending, 100000);

        $summary = $this->summaryFor($admin);

        foreach (OrderStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $summary, "Status {$status->value} must appear even at zero.");
        }
        $this->assertSame(0, $summary['paid']['count']);
        $this->assertSame(0, $summary['paid']['total_satang']);
    }

    public function test_an_agent_only_counts_their_own_orders(): void
    {
        // THE LEAK. Through a counts endpoint, where nobody would think to
        // look for one.
        [$company, , $agent] = $this->companyWithStaff();
        $other = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->orderFor($company, $agent, OrderStatus::Pending, 100000);
        $this->orderFor($company, $other, OrderStatus::Pending, 900000);

        $summary = $this->summaryFor($agent);

        $this->assertSame(1, $summary['pending']['count']);
        $this->assertSame(100000, $summary['pending']['total_satang']);
    }

    public function test_a_company_admin_counts_the_whole_company(): void
    {
        [$company, $admin, $agent] = $this->companyWithStaff();
        $other = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->orderFor($company, $agent, OrderStatus::Pending, 100000);
        $this->orderFor($company, $other, OrderStatus::Pending, 900000);

        $summary = $this->summaryFor($admin);

        $this->assertSame(2, $summary['pending']['count']);
        $this->assertSame(1000000, $summary['pending']['total_satang']);
    }

    public function test_another_companys_orders_are_never_counted(): void
    {
        [$company, $admin, $agent] = $this->companyWithStaff();
        $this->orderFor($company, $agent, OrderStatus::Pending, 100000);

        [$otherCompany, , $otherAgent] = $this->companyWithStaff();
        $this->orderFor($otherCompany, $otherAgent, OrderStatus::Pending, 900000);

        $summary = $this->summaryFor($admin);

        $this->assertSame(1, $summary['pending']['count']);
        $this->assertSame(100000, $summary['pending']['total_satang']);
    }

    public function test_the_summary_and_the_list_agree(): void
    {
        /*
         * THE ASSERTION THIS FILE IS FOR. The tab count and the rows beneath
         * it are two queries; if they ever answer over different sets, an
         * admin works a queue that will not empty.
         */
        [$company, $admin, $agent] = $this->companyWithStaff();
        $other = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->orderFor($company, $agent, OrderStatus::AwaitingVerification, 100000);
        $this->orderFor($company, $other, OrderStatus::AwaitingVerification, 200000);
        $this->orderFor($company, $agent, OrderStatus::Paid, 300000);

        foreach ([$admin, $agent] as $actor) {
            $summary = $this->summaryFor($actor);
            $listed = $this->actingAs($actor)
                ->getJson('/api/v1/orders?status=awaiting_verification')
                ->assertOk()
                ->json('meta.total');

            $this->assertSame(
                $summary['awaiting_verification']['count'],
                $listed,
                'The tab count must be the number of rows the tab shows.'
            );
        }
    }

    public function test_summary_is_not_mistaken_for_an_order_id(): void
    {
        // /orders/{order} is registered after this route; if it were first,
        // "summary" would be resolved as an id and 404 a route that exists.
        [, $admin] = $this->companyWithStaff();

        $this->actingAs($admin)->getJson('/api/v1/orders/summary')->assertOk();
    }

    public function test_a_guest_gets_nothing(): void
    {
        $this->getJson('/api/v1/orders/summary')->assertUnauthorized();
    }
}
