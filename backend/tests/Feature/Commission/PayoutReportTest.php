<?php

namespace Tests\Feature\Commission;

use App\Enums\WithdrawalSource;
use App\Enums\WithdrawalStatus;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-16 — รายงานการจ่าย, the read-only record.
 *
 * Owner: "หน้าเดิมเป็นสรุปรายการ เป็น Log ที่โอนแล้ว รอโอนโดยบัญชี Filter ได้
 * Export เป็น CSV ได้ตามที่ Filter".
 *
 * ── THE ONE PROMISE THIS PAGE MAKES ──
 *
 * The figure at the top, the rows underneath it and the file that comes out of
 * it are THE SAME SET. That is the whole reason to have a report rather than
 * three screens that each count something slightly different — and it is the
 * property that a later edit to two of three filter blocks silently breaks. All
 * three read one query (reportQuery), and the tests below are written against
 * that promise rather than against each endpoint separately.
 *
 * ── AND THE ONE DECISION THAT NEEDED AN EXPLICIT ANSWER ──
 *
 * A payout has two dates and they answer different questions. "How much did we
 * pay out in September" is transferred_at; "how much was asked for in
 * September" is created_at. A request raised in August and transferred in
 * September belongs to both answers, once each — so `date_basis` is a
 * parameter, not an assumption.
 */
class PayoutReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_every_status_including_the_ones_the_work_screen_hides(): void
    {
        /*
         * This is what makes it a record rather than a second queue. Rejected
         * and cancelled rows have no home on the working screen — nothing will
         * ever happen to them again — and they are exactly what somebody opens
         * a report to find.
         */
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred);
        $this->payout($company, 45050, WithdrawalStatus::Approved);
        $this->payout($company, 30000, WithdrawalStatus::Rejected);
        $this->payout($company, 20000, WithdrawalStatus::Cancelled);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_it_narrows_to_the_statuses_asked_for(): void
    {
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred);
        $this->payout($company, 45050, WithdrawalStatus::Approved);
        $this->payout($company, 30000, WithdrawalStatus::Rejected);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report?statuses[]=transferred&statuses[]=approved')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            ['transferred', 'approved'],
            array_column($response->json('data'), 'status'),
        );
    }

    public function test_an_unknown_status_narrows_to_nothing_rather_than_widening_to_everything(): void
    {
        /*
         * The direction of a typo matters on a money report. A filter the
         * server does not recognise must show the reader LESS than they asked
         * for, which is visible, rather than the whole table under a heading
         * that says it is filtered.
         */
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report?statuses[]=definitely_not_a_status')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_narrows_by_which_door_the_payout_came_through(): void
    {
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred, source: WithdrawalSource::CompanyPayout);
        $this->payout($company, 45050, WithdrawalStatus::Transferred, source: WithdrawalSource::AgentRequest);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report?sources[]=agent_request')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('agent_request', $response->json('data.0.source'));
    }

    public function test_the_date_window_counts_from_the_transfer_date_by_default(): void
    {
        /*
         * THE ONE THAT MATTERS about date_basis. This row was RAISED in August
         * and TRANSFERRED in September, so "September" means it — and a report
         * that silently windowed on created_at would leave it out of the month
         * whose bank statement it is on.
         */
        [$company, $admin] = $this->world();
        $this->payout(
            $company,
            120000,
            WithdrawalStatus::Transferred,
            createdAt: '2026-08-20',
            transferredAt: '2026-09-03',
        );

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_same_window_on_the_request_date_answers_the_other_question(): void
    {
        [$company, $admin] = $this->world();
        $this->payout(
            $company,
            120000,
            WithdrawalStatus::Transferred,
            createdAt: '2026-08-20',
            transferredAt: '2026-09-03',
        );

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report?date_basis=created_at&date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report?date_basis=created_at&date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_money_that_has_not_moved_falls_outside_a_transfer_date_window(): void
    {
        // Correct, not a gap: a payout still waiting for accounting was not
        // paid out in any month, so no month's total may claim it.
        [$company, $admin] = $this->world();
        $this->payout($company, 45050, WithdrawalStatus::Approved, createdAt: '2026-09-10');

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_finds_a_payee_by_name(): void
    {
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred, agentName: 'สมหญิง ใจดี');
        $this->payout($company, 45050, WithdrawalStatus::Transferred, agentName: 'วิชัย มั่นคง');

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report?q='.urlencode('สมหญิง'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertStringContainsString('สมหญิง', $response->json('data.0.agent_name'));
    }

    public function test_the_totals_describe_the_filtered_set_and_not_the_whole_table(): void
    {
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred);
        $this->payout($company, 45050, WithdrawalStatus::Approved);
        $this->payout($company, 999900, WithdrawalStatus::Rejected);

        $summary = $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report/summary?statuses[]=transferred&statuses[]=approved')
            ->assertOk()
            ->json('data');

        $this->assertSame(165050, $summary['total_satang']);
        $this->assertSame(2, $summary['count']);
        $this->assertSame(120000, $summary['transferred_satang']);
        $this->assertSame(45050, $summary['outstanding_satang']);
    }

    public function test_rejected_money_is_not_counted_as_waiting_to_be_transferred(): void
    {
        /*
         * "ยังไม่โอน" has to mean money that is still going somewhere. A
         * rejected request is in the list — that is what a record is for — but
         * counting it as outstanding would state a liability the company does
         * not have, on the figure an admin reads to know what is still owed.
         */
        [$company, $admin] = $this->world();
        $this->payout($company, 45050, WithdrawalStatus::Approved);
        $this->payout($company, 999900, WithdrawalStatus::Rejected);

        $summary = $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $summary['count'], 'both rows are in the report');
        $this->assertSame(45050, $summary['outstanding_satang'], 'only the open one is owed');
    }

    public function test_the_totals_and_the_rows_are_the_same_set(): void
    {
        // The promise this page makes, asserted directly rather than inferred
        // from two endpoints happening to agree in the examples above.
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred);
        $this->payout($company, 45050, WithdrawalStatus::Approved);
        $this->payout($company, 30000, WithdrawalStatus::Rejected);

        $query = '?statuses[]=transferred&statuses[]=rejected';

        $rows = $this->actingAs($admin)->getJson('/api/v1/commission-withdrawals/report'.$query)->json('data');
        $summary = $this->actingAs($admin)->getJson('/api/v1/commission-withdrawals/report/summary'.$query)->json('data');

        $this->assertSame(count($rows), $summary['count']);
        $this->assertSame(array_sum(array_column($rows, 'amount_satang')), $summary['total_satang']);
    }

    public function test_the_csv_contains_exactly_the_filtered_rows(): void
    {
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred, agentName: 'สมหญิง ใจดี');
        $this->payout($company, 999900, WithdrawalStatus::Rejected, agentName: 'วิชัย มั่นคง');

        $csv = $this->actingAs($admin)
            ->get('/api/v1/commission-withdrawals/report/export?statuses[]=transferred')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('สมหญิง ใจดี', $csv);
        $this->assertStringNotContainsString('วิชัย มั่นคง', $csv);
        // BR-3 — satang upstream, baht in the file.
        $this->assertStringContainsString('1200.00', $csv);
    }

    public function test_the_csv_masks_the_account_number(): void
    {
        /*
         * Deliberately UNLIKE the payout file from
         * AgentCommissionSummaryController, which carries full account numbers
         * because it is a bank instruction. This one is a record that gets
         * filed, mailed and forwarded, and nothing it is used for needs the
         * digits back.
         */
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred);

        $csv = $this->actingAs($admin)
            ->get('/api/v1/commission-withdrawals/report/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('1234567890', $csv);
        $this->assertStringContainsString('7890', $csv);
    }

    public function test_the_csv_opens_as_utf8_in_excel(): void
    {
        // Without the BOM the Thai headers and agent names render as mojibake,
        // which defeats the point of a file made to be opened and read.
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred);

        $csv = $this->actingAs($admin)
            ->get('/api/v1/commission-withdrawals/report/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_a_formula_in_a_payee_name_cannot_execute_in_the_spreadsheet(): void
    {
        /*
         * SECURITY AUDIT 2026-08-21, V9 — the same rule the other export
         * follows. Agent names are free text an agent types about themselves,
         * the reader opens this file in Excel, and Excel is what executes the
         * payload.
         */
        [$company, $admin] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred, agentName: '=HYPERLINK("http://x") ใจดี');

        $csv = $this->actingAs($admin)
            ->get('/api/v1/commission-withdrawals/report/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_another_companys_payouts_are_not_in_the_report(): void
    {
        // BR-6. The report reads the same visibleTo() as the queue, so this is
        // a regression guard on that staying true rather than a new rule.
        [$company, $admin] = $this->world();
        [$other] = $this->world();
        $this->payout($company, 120000, WithdrawalStatus::Transferred, agentName: 'ของเรา เอง');
        $this->payout($other, 999900, WithdrawalStatus::Transferred, agentName: 'ของเขา ไม่เกี่ยว');

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/commission-withdrawals/report')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertStringContainsString('ของเรา', $response->json('data.0.agent_name'));
    }

    public function test_an_agent_sees_only_their_own_payouts(): void
    {
        [$company, $admin] = $this->world();
        $mine = $this->payout($company, 120000, WithdrawalStatus::Transferred);
        $this->payout($company, 45050, WithdrawalStatus::Transferred);

        $agent = User::withoutGlobalScopes()->find($mine->agent_id);

        $this->actingAs($agent)
            ->getJson('/api/v1/commission-withdrawals/report')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($admin->company_id, $company->id);
    }

    /** @return array{0: Company, 1: User} */
    private function world(): array
    {
        $company = Company::factory()->create();

        return [$company, User::factory()->companyAdmin()->create(['company_id' => $company->id])];
    }

    /**
     * A finished payout row in whatever state the test needs.
     *
     * Written straight to the table rather than driven through the service:
     * this file is about READING, and half these states (rejected, cancelled)
     * would each need their own three-step setup to reach honestly. The states
     * themselves are pinned by the tests that produce them.
     */
    private function payout(
        Company $company,
        int $satang,
        WithdrawalStatus $status,
        WithdrawalSource $source = WithdrawalSource::CompanyPayout,
        ?string $agentName = null,
        ?string $createdAt = null,
        ?string $transferredAt = null,
    ): CommissionWithdrawalRequest {
        $parts = $agentName !== null ? explode(' ', $agentName, 2) : ['ตัวแทน', 'ทดสอบ'];

        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
        ]);

        $row = CommissionWithdrawalRequest::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'amount_satang' => $satang,
            'status' => $status,
            'source' => $source,
            'bank_name' => 'กสิกรไทย',
            'bank_account_number' => '1234567890',
            'bank_account_holder_name' => 'ตัวแทน ทดสอบ',
            'transferred_at' => $status === WithdrawalStatus::Transferred
                ? ($transferredAt ?? $createdAt ?? now())
                : null,
        ]);

        /*
         * created_at is set AFTER the insert, through the query builder.
         *
         * Passing it to create() does nothing — Eloquent stamps its own value
         * over it whenever $timestamps is on — which is how the date_basis test
         * first "passed" against a row silently created today. The two dates on
         * a payout are the whole point of that test, so the fixture has to be
         * able to put them where it says it does.
         */
        if ($createdAt !== null) {
            \Illuminate\Support\Facades\DB::table('commission_withdrawal_requests')
                ->where('id', $row->id)
                ->update(['created_at' => $createdAt]);

            $row->refresh();
        }

        return $row;
    }
}
