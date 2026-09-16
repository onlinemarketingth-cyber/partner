<?php

namespace Tests\Feature\Commission;

use App\Enums\IdDocumentType;
use App\Enums\PaymentStatus;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\Commission\CommissionWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-16 — TWO NUMBERS, ONE NAME, AND A LOOP WITH NO WAY OUT.
 *
 * Owner: "ผมกดยืนยันการจ่ายไปแล้ว แต่ปัญหาคือหน้าจอ Ui ยังขึ้นค้างจ่ายอยู่".
 *
 * แนวทาง C deliberately leaves the commission ledger alone when a payout is
 * raised — the money has not moved, and it is settled only when somebody
 * records the transfer. So after a successful ตั้งจ่าย:
 *
 *   · /agent-commission-summary still reported the FULL pending balance
 *   · CommissionWithdrawalService::availableSatang() reported 0, because an
 *     open payout had reserved all of it
 *
 * The screen drew the row completely unchanged — same amount, still ticked,
 * still selectable — and every further press was refused, permanently, with
 * "ยอดค้างจ่ายของคนนี้เปลี่ยนไปแล้ว (0.00 บาท ไม่ตรงกับ 1,046.50 บาท)". Both
 * figures were correct; they were different things called the same name, and
 * only one of them was on screen.
 *
 * What these pin is that the summary now reports BOTH, and that the one the
 * screen sends is the one the server checks.
 */
class PayoutSummaryReservedBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_before_any_payout_available_equals_what_is_owed(): void
    {
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, 104650);

        $row = $this->summaryRow($admin, $agent);

        $this->assertSame(104650, $row['total_pending_satang']);
        $this->assertSame(0, $row['reserved_satang']);
        $this->assertSame(104650, $row['available_satang']);
    }

    public function test_raising_a_payout_leaves_the_owed_figure_alone(): void
    {
        /*
         * The behaviour that confused the owner, stated outright rather than
         * "fixed": the ledger row is still Pending because the money has not
         * moved, and "what is this person owed" is still the full amount. It
         * is the OTHER number that changed.
         */
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, 104650);

        app(CommissionWithdrawalService::class)->payOutSettling($agent->fresh(), 104650, $admin);

        $row = $this->summaryRow($admin, $agent);

        $this->assertSame(104650, $row['total_pending_satang'], 'still owed — nothing has been transferred');
    }

    public function test_and_reports_the_whole_balance_as_reserved_with_nothing_left_to_raise(): void
    {
        // THE ONE THAT MATTERS. Without this the screen keeps offering a press
        // that the server can only refuse, forever.
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, 104650);

        app(CommissionWithdrawalService::class)->payOutSettling($agent->fresh(), 104650, $admin);

        $row = $this->summaryRow($admin, $agent);

        $this->assertSame(104650, $row['reserved_satang']);
        $this->assertSame(0, $row['available_satang']);
    }

    public function test_available_matches_the_figure_the_press_is_checked_against(): void
    {
        /*
         * The agreement test. These are two implementations of "how much may
         * be raised for this person" — one for the screen, one that refuses
         * the press — and the whole defect was that they disagreed.
         */
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, 104650);
        app(CommissionWithdrawalService::class)->payOutSettling($agent->fresh(), 104650, $admin);

        $row = $this->summaryRow($admin, $agent);

        $this->assertSame(
            app(CommissionWithdrawalService::class)->availableSatang($agent->fresh()),
            $row['available_satang'],
        );
    }

    public function test_a_partly_reserved_balance_reports_the_remainder(): void
    {
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, 100000);
        $this->owe($company, $agent, 50000);

        // Raise for part of it only.
        app(CommissionWithdrawalService::class)->payOut($agent->fresh(), 100000, $admin);

        $row = $this->summaryRow($admin, $agent);

        $this->assertSame(150000, $row['total_pending_satang']);
        $this->assertSame(100000, $row['reserved_satang']);
        $this->assertSame(50000, $row['available_satang']);
    }

    public function test_a_rejected_payout_releases_the_money_again(): void
    {
        /*
         * WithdrawalStatus::open() is the definition of "reserved", and a
         * rejected request is not open. If this leaked, a refused payout would
         * lock an agent's balance away with no screen able to explain why.
         */
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, 104650);

        $service = app(CommissionWithdrawalService::class);
        $request = $service->request($agent->fresh(), 104650);
        $service->reject($request, $admin, 'ทดสอบ');

        $row = $this->summaryRow($admin, $agent);

        $this->assertSame(0, $row['reserved_satang']);
        $this->assertSame(104650, $row['available_satang']);
    }

    public function test_an_unmeasured_balance_stays_unmeasured_rather_than_becoming_zero(): void
    {
        /*
         * §3.7 / F-10, applied to the new field. Filtering by "จ่ายแล้ว" means
         * nobody measured the pending bucket — so an available balance derived
         * from it is unmeasured too. A 0 here would grey out the row's
         * checkbox for a reason that is not true.
         */
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, 104650);
        // A settled row as well, so the agent still appears under the paid
        // filter at all — the filter narrows BEFORE aggregation, so an agent
        // with nothing paid simply produces no group.
        $this->owe($company, $agent, 50000, PaymentStatus::Paid);

        $row = collect($this->actingAs($admin)
            ->getJson('/api/v1/agent-commission-summary?payment_status=paid')
            ->assertOk()
            ->json('data'))
            ->firstWhere('agent_id', $agent->id);

        $this->assertNull($row['total_pending_satang']);
        $this->assertNull($row['available_satang']);
    }

    public function test_another_agents_open_payout_does_not_reserve_this_ones_money(): void
    {
        [$company, $admin, $agent] = $this->world();
        $colleague = $this->payableAgent($company);
        $this->owe($company, $agent, 104650);
        $this->owe($company, $colleague, 200000);

        app(CommissionWithdrawalService::class)->payOutSettling($colleague->fresh(), 200000, $admin);

        $row = $this->summaryRow($admin, $agent);

        $this->assertSame(0, $row['reserved_satang']);
        $this->assertSame(104650, $row['available_satang']);
    }

    /** @return array{0: Company, 1: User, 2: User} */
    private function world(): array
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        return [$company, $admin, $this->payableAgent($company)];
    }

    private function payableAgent(Company $company): User
    {
        return User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => '1234567890123',
            'id_document_type' => IdDocumentType::ThaiNationalId,
            'bank_name' => 'กสิกรไทย',
            'bank_account_number' => '1234567890',
            'bank_account_holder_name' => 'ตัวแทน ทดสอบ',
        ]);
    }

    private function owe(Company $company, User $agent, int $satang, PaymentStatus $status = PaymentStatus::Pending): void
    {
        CommissionLedger::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => Product::factory()->create(['company_id' => $company->id])->id,
            'rate_type_applied' => 'percentage',
            'rate_applied' => 500,
            'amount_satang' => $satang,
            'payment_status' => $status,
            'paid_at' => $status === PaymentStatus::Paid ? now() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function summaryRow(User $admin, User $agent): array
    {
        return collect($this->actingAs($admin)
            ->getJson('/api/v1/agent-commission-summary')
            ->assertOk()
            ->json('data'))
            ->firstWhere('agent_id', $agent->id);
    }
}
