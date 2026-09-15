<?php

namespace Tests\Feature\Commission;

use App\Enums\WithdrawalStatus;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-15 — the band that says where the money is.
 *
 * Owner: "มันดูแล้วไม่เข้าใจทันทีว่า User เข้ามาต้องทำอะไร ดูอะไรบ้าง".
 *
 * The queue screen loads ONE status at a time, so it could describe the step
 * in front of the reader and nothing about the two either side — four boxes
 * of chrome and no figure anywhere. This endpoint feeds the three-step rail
 * that replaced the flat filter chips.
 *
 * ── WHAT CAN GO WRONG WITH A HEADLINE NUMBER ──
 *
 * A total printed ABOVE a list is read as describing that list. So the two
 * things worth pinning are that the figures count exactly the rows the tabs
 * list, and that they are scoped to precisely the same people and companies
 * the list is — a summary that widened even slightly would print one
 * tenant's money over another tenant's rows.
 */
class WithdrawalQueueSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_a_count_and_a_total_for_every_step(): void
    {
        [$company, $admin] = $this->world();

        $this->queue($company, WithdrawalStatus::PendingReview, [90000]);
        $this->queue($company, WithdrawalStatus::Approved, [155000, 90000]);
        $this->queue($company, WithdrawalStatus::Transferred, [412000]);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/summary')
            ->assertOk()
            ->assertJsonPath('data.pending_review.count', 1)
            ->assertJsonPath('data.pending_review.satang', 90000)
            ->assertJsonPath('data.approved.count', 2)
            ->assertJsonPath('data.approved.satang', 245000)
            ->assertJsonPath('data.transferred.count', 1)
            ->assertJsonPath('data.transferred.satang', 412000);
    }

    public function test_a_step_with_nothing_in_it_answers_zero_rather_than_going_missing(): void
    {
        /*
         * A missing key would make the screen choose between rendering
         * nothing in that step and inventing a zero of its own. Zero is the
         * true answer, and the server is the side that knows it — the same
         * rule the payout summary already follows for an unmeasured bucket,
         * in reverse.
         */
        [, $admin] = $this->world();

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/summary')
            ->assertOk()
            ->assertJsonPath('data.pending_review.count', 0)
            ->assertJsonPath('data.pending_review.satang', 0)
            ->assertJsonPath('data.rejected.count', 0)
            ->assertJsonPath('data.cancelled.count', 0);
    }

    public function test_the_totals_describe_the_same_rows_the_tabs_list(): void
    {
        /*
         * THE ONE THAT MATTERS. These figures sit directly above the list, so
         * they are read as describing it. If the summary ever counted a
         * window the list does not — "this month", say, which reads better
         * and was the first draft — the header and the rows underneath it
         * would disagree the moment anybody opened that tab.
         */
        [$company, $admin] = $this->world();
        $this->queue($company, WithdrawalStatus::Transferred, [100000, 200000, 300000]);

        $summary = $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/summary')
            ->assertOk()
            ->json('data.transferred');

        $listed = $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals?status=transferred')
            ->assertOk()
            ->json('data');

        $this->assertSame(count($listed), $summary['count']);
        $this->assertSame(array_sum(array_column($listed, 'amount_satang')), $summary['satang']);
    }

    public function test_another_companys_money_is_not_counted(): void
    {
        // BR-6. The figure is printed over one company's queue; borrowing a
        // number from another tenant would be both a leak and a total nobody
        // could reconcile against anything on screen.
        [$company, $admin] = $this->world();
        [$other] = $this->world();

        $this->queue($company, WithdrawalStatus::Approved, [100000]);
        $this->queue($other, WithdrawalStatus::Approved, [999900]);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/summary')
            ->assertOk()
            ->assertJsonPath('data.approved.satang', 100000);
    }

    public function test_a_super_admins_header_company_narrows_it_the_same_way_as_the_list(): void
    {
        /*
         * TenantScope does not pin a Super Admin, so without the same
         * CompanyScopeFilter the list uses, this band would print every
         * tenant's money above one tenant's rows — the defect that was found
         * on the list itself in September.
         */
        [$company] = $this->world();
        [$other] = $this->world();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->queue($company, WithdrawalStatus::Approved, [100000]);
        $this->queue($other, WithdrawalStatus::Approved, [700000]);

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/commission-withdrawals/summary?company_id={$company->id}")
            ->assertOk()
            ->assertJsonPath('data.approved.satang', 100000);

        // No company_id is still the deliberate read-across view.
        $this->actingAs($superAdmin)
            ->getJson('/api/v1/commission-withdrawals/summary')
            ->assertOk()
            ->assertJsonPath('data.approved.satang', 800000);
    }

    public function test_an_agent_is_summarised_over_their_own_requests_only(): void
    {
        // The same forced self-filter the list applies. An agent seeing the
        // company's total would be reading how much everyone else is owed.
        [$company] = $this->world();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $colleague = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->queue($company, WithdrawalStatus::Approved, [50000], $agent);
        $this->queue($company, WithdrawalStatus::Approved, [880000], $colleague);

        $this->actingAs($agent)
            ->getJson('/api/v1/commission-withdrawals/summary')
            ->assertOk()
            ->assertJsonPath('data.approved.satang', 50000);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson('/api/v1/commission-withdrawals/summary')->assertUnauthorized();
    }

    /** @return array{0: Company, 1: User} */
    private function world(): array
    {
        $company = Company::factory()->create();

        return [$company, User::factory()->companyAdmin()->create(['company_id' => $company->id])];
    }

    /** @param  list<int>  $amountsSatang */
    private function queue(Company $company, WithdrawalStatus $status, array $amountsSatang, ?User $agent = null): void
    {
        $agent ??= User::factory()->agent()->create(['company_id' => $company->id]);

        foreach ($amountsSatang as $satang) {
            CommissionWithdrawalRequest::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'agent_id' => $agent->id,
                'amount_satang' => $satang,
                'status' => $status,
            ]);
        }
    }
}
