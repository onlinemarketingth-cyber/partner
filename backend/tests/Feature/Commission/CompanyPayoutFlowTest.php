<?php

namespace Tests\Feature\Commission;

use App\Enums\IdDocumentType;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Enums\WithdrawalSource;
use App\Enums\WithdrawalStatus;
use App\Models\CommissionLedger;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\Commission\CommissionHouseAccountService;
use App\Services\Commission\CommissionWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-09-15 — ONE PAYOUT OBJECT, TWO DOORS (แนวทาง C).
 *
 * Owner: "ระบบเรามีข้อจำกัดในการโอนเงินไปให้ Agent เราใช้วิธีโอนเองผ่านระบบการ
 * ทำงาน Bank เราไม่ได้ Payment auto ซึ่งต้องได้รับข้อมูลจากฝ่ายบัญชีก่อนว่าโอนแล้ว
 * จึงมากดยืนยัน."
 *
 * Their real process is three days long — decide, accounting transfers,
 * confirm — and the admin's payout button was one click that did all three at
 * once: it settled the ledger irreversibly (BR-4) and emailed the agent that
 * their money had arrived, before accounting had opened the bank.
 *
 * The withdrawal table already modelled that process correctly, on the route
 * nobody used. So the admin's button now creates the SAME object, and these
 * tests are about the three things that makes true:
 *
 *   1. THE TWO DOORS MEET IN ONE ROOM. Same table, same states, same audit
 *      trail; only the starting state and the company minimum differ.
 *   2. THE LEDGER WAITS FOR THE TRANSFER. Raising a payout must change no
 *      commission row — a row settled on the strength of a decision is a row
 *      that cannot be un-settled if accounting never sends the money.
 *   3. SOMEBODY IS TOLD, AT EVERY STEP. This flow had four state changes and
 *      zero notifications, while the one-click route — where nobody was
 *      waiting — sent an email. Exactly backwards.
 *
 * This file is also the first real coverage of the withdrawal lifecycle: only
 * its company scoping was pinned before, on a path that moves money.
 */
class CompanyPayoutFlowTest extends TestCase
{
    use RefreshDatabase;

    // ── The two doors meet in one room ────────────────────────────────

    public function test_an_admin_raising_a_payout_starts_it_at_awaiting_transfer(): void
    {
        /*
         * Already decided, because the press IS the decision. Sending it to a
         * review queue so the same admin can approve their own press would be
         * a rubber stamp, and rubber stamps are what teach people to click
         * through a queue without reading it.
         */
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, [150000]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 150000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.source', 'company_payout')
            ->assertJsonPath('data.source_label', 'บริษัทตั้งจ่าย')
            ->assertJsonPath('data.amount_satang', 150000);

        $payout = CommissionWithdrawalRequest::withoutGlobalScopes()->sole();

        // Who authorised it, recorded then and there — the question a review
        // step would otherwise have answered.
        $this->assertSame($admin->id, $payout->decided_by_user_id);
        $this->assertNotNull($payout->decided_at);
    }

    public function test_an_agents_own_request_still_starts_at_pending_review(): void
    {
        // The control. Merging the two doors must not quietly approve what an
        // agent asks for.
        [$company, , $agent] = $this->world();
        $this->owe($company, $agent, [100000]);

        $request = $this->payouts()->request($agent->fresh(), 100000);

        $this->assertSame(WithdrawalStatus::PendingReview, $request->status);
        $this->assertSame(WithdrawalSource::AgentRequest, $request->fresh()->source);
        $this->assertNull($request->decided_by_user_id);
    }

    public function test_the_company_minimum_refuses_an_agent_and_not_the_company(): void
    {
        /*
         * The minimum exists so agents do not queue up ฿20 transfers. It has
         * nothing to say about the company settling what it owes — and if it
         * did, a small balance would be stuck forever, because the agent
         * cannot request it either.
         */
        [$company, $admin, $agent] = $this->world();
        $company->forceFill(['min_withdrawal_satang' => 100000])->save();
        $this->owe($company, $agent, [5000]);

        $this->actingAs($agent)
            ->postJson('/api/v1/commission-withdrawals', ['amount_satang' => 5000])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 5000,
            ])
            ->assertCreated();
    }

    // ── The ledger waits for the transfer ─────────────────────────────

    public function test_raising_a_payout_settles_no_commission_row(): void
    {
        /*
         * THE WHOLE POINT OF แนวทาง C. The old button flipped these rows to
         * Paid at the moment of the decision — days before the money moved,
         * and with no way back (BR-4). Accounting may yet come back and say
         * the transfer failed.
         */
        [$company, $admin, $agent] = $this->world();
        $rows = $this->owe($company, $agent, [100000, 50000]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 150000,
            ])
            ->assertCreated();

        foreach ($rows as $row) {
            $this->assertSame('pending', $row->refresh()->payment_status->value);
            $this->assertNull($row->paid_at);
        }
    }

    public function test_recording_the_transfer_is_what_settles_them(): void
    {
        [$company, $admin, $agent] = $this->world();
        $rows = $this->owe($company, $agent, [100000, 50000]);

        $payout = $this->payouts()->payOut($agent->fresh(), 150000, $admin);

        $this->actingAs($admin)
            ->postJson("/api/v1/commission-withdrawals/{$payout->id}/mark-transferred", [
                'transfer_reference' => 'KBANK-99887',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'transferred');

        foreach ($rows as $row) {
            $this->assertSame('paid', $row->refresh()->payment_status->value);
        }
    }

    public function test_a_row_the_payout_only_partly_drew_on_stays_owed(): void
    {
        /*
         * An arbitrary amount does not divide neatly across indivisible
         * ledger rows, which is why the allocation table exists at all. A row
         * half-drawn is half-owed, and marking it paid would lose the rest.
         */
        [$company, $admin, $agent] = $this->world();
        $rows = $this->owe($company, $agent, [100000, 50000]);

        // Agent-side, because only the agent can ask for a partial amount.
        $partial = $this->payouts()->request($agent->fresh(), 120000);
        $this->payouts()->approve($partial, $admin);
        $this->payouts()->markTransferred($partial->fresh(), $admin, null);

        // Oldest first: the 100,000 row is covered, the 50,000 one is drawn
        // on for 20,000 and still owes 30,000.
        $this->assertSame('paid', $rows[0]->refresh()->payment_status->value);
        $this->assertSame('pending', $rows[1]->refresh()->payment_status->value);
    }

    // ── The guards ────────────────────────────────────────────────────

    public function test_a_balance_that_moved_since_the_screen_loaded_aborts_the_payout(): void
    {
        /*
         * Between the screen loading and the press, a sale can complete and
         * write a new commission row. Paying the new total would be paying an
         * amount nobody authorised, permanently.
         */
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, [100000]);
        $this->owe($company, $agent, [75000]); // landed after the screen rendered

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 100000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_total_satang');

        $this->assertSame(0, CommissionWithdrawalRequest::withoutGlobalScopes()->count());
    }

    public function test_an_agent_cannot_raise_a_payout_for_themselves(): void
    {
        // Raising one creates something already approved, so anything less
        // than the decide permission here would be a way around the review.
        [$company, , $agent] = $this->world();
        $this->owe($company, $agent, [100000]);

        $this->actingAs($agent)
            ->postJson('/api/v1/commission-withdrawals/payout', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 100000,
            ])
            ->assertForbidden();

        $this->assertSame(0, CommissionWithdrawalRequest::withoutGlobalScopes()->count());
    }

    public function test_a_company_admin_cannot_raise_one_for_another_companys_agent(): void
    {
        [, $admin] = $this->world();
        [$otherCompany, , $stranger] = $this->world();
        $this->owe($otherCompany, $stranger, [100000]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout', [
                'agent_id' => $stranger->id,
                'expected_total_satang' => 100000,
            ])
            ->assertForbidden();
    }

    public function test_the_company_cannot_pay_out_its_own_seat(): void
    {
        /*
         * The house account accrues commission rows like a team leader does,
         * and every one of them is money the company already holds. A payout
         * would be the company transferring to itself.
         */
        [$company, $admin] = $this->world();
        $house = app(CommissionHouseAccountService::class)->create($company);
        $this->owe($company, $house, [44850]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout', [
                'agent_id' => $house->id,
                // availableSatang() answers 0 for the seat, so this cannot
                // even agree with the screen — asserted as a 422 either way.
                'expected_total_satang' => 44850,
            ])
            ->assertStatus(422);

        $this->assertSame(0, CommissionWithdrawalRequest::withoutGlobalScopes()->count());
    }

    public function test_an_agent_with_no_bank_account_is_refused_in_words_the_admin_can_act_on(): void
    {
        // The admin can fix this without leaving the payout screen — it has
        // the bank fields on the same row — so the message names the problem
        // rather than telling them to go and fill in their own details.
        [$company, $admin, $agent] = $this->world();
        $agent->forceFill(['bank_account_number' => null])->save();
        $this->owe($company, $agent, [100000]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 100000,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['ตั้งจ่ายไม่ได้ — สมาชิกคนนี้ยังกรอกเอกสารยืนยันตัวตนหรือบัญชีธนาคารไม่ครบ']);
    }

    // ── Somebody is told, at every step ───────────────────────────────

    public function test_an_agents_request_reaches_the_admins_who_can_act_on_it(): void
    {
        /*
         * The owner's own report: "ตัวแทนขอเบิกผ่านหน้า frontend แล้วแจ้งให้
         * admin ทราบ อันนี้ไม่มีการแจ้งเตือนเลย". The agent is blocked until a
         * human opens a queue, and nothing said so.
         */
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, [100000]);

        $this->actingAs($agent)
            ->postJson('/api/v1/commission-withdrawals', ['amount_satang' => 100000])
            ->assertCreated();

        $this->assertSame(1, $this->notificationCount($admin, NotificationType::CommissionWithdrawalRequested));
        // Not to the agent: they are the one who just pressed the button.
        $this->assertSame(0, $this->notificationCount($agent, NotificationType::CommissionWithdrawalRequested));
    }

    public function test_approving_tells_the_agent_it_is_coming_and_does_not_claim_it_arrived(): void
    {
        /*
         * "อนุมัติแล้ว" is not "จ่ายแล้ว". An agent told the wrong one of
         * those, who then sees nothing in their bank for three days, will ask
         * somebody — which is the support call this whole change exists to
         * prevent.
         */
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, [100000]);
        $request = $this->payouts()->request($agent->fresh(), 100000);

        $this->payouts()->approve($request, $admin);

        $this->assertSame(1, $this->notificationCount($agent, NotificationType::CommissionWithdrawalDecided));
        $this->assertSame(0, $this->notificationCount($agent, NotificationType::CommissionPaid));
        $this->assertStringContainsString('รอโอน', $this->lastBody($agent));
    }

    public function test_rejecting_carries_the_reason_the_admin_was_made_to_type(): void
    {
        // The admin is REQUIRED to give a reason precisely because the agent
        // needs it — and it used to live only on a screen they had to think
        // to go and open.
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, [100000]);
        $request = $this->payouts()->request($agent->fresh(), 100000);

        $this->payouts()->reject($request, $admin, 'บัญชีธนาคารไม่ตรงกับชื่อสมาชิก');

        $this->assertStringContainsString('บัญชีธนาคารไม่ตรงกับชื่อสมาชิก', $this->lastBody($agent));
    }

    public function test_the_money_email_fires_at_the_transfer_and_not_before(): void
    {
        /*
         * CommissionPaid is the type with email enabled. It used to fire when
         * an admin DECIDED to pay; it now fires when somebody confirms the
         * bank actually sent it — the only moment at which the sentence
         * "เงินเข้าบัญชีของคุณแล้ว" is true.
         */
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, [100000]);
        $payout = $this->payouts()->payOut($agent->fresh(), 100000, $admin);

        $this->assertSame(0, $this->notificationCount($agent, NotificationType::CommissionPaid));

        $this->payouts()->markTransferred($payout->fresh(), $admin, 'KBANK-1234');

        $this->assertSame(1, $this->notificationCount($agent, NotificationType::CommissionPaid));
        $this->assertStringContainsString('KBANK-1234', $this->lastBody($agent));
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function payouts(): CommissionWithdrawalService
    {
        return app(CommissionWithdrawalService::class);
    }

    /** @return array{0: Company, 1: User, 2: User} company, its admin, one payable agent */
    private function world(): array
    {
        $company = Company::factory()->create(['min_withdrawal_satang' => null]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // Complete payout details, or every payout below is refused before it
        // reaches the thing the test is about.
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => '1234567890123',
            'id_document_type' => IdDocumentType::cases()[0],
            'bank_name' => 'กสิกรไทย',
            'bank_account_number' => '1234567890',
            'bank_account_holder_name' => 'สมชาย ใจดี',
        ]);

        return [$company, $admin, $agent];
    }

    /**
     * Pending commission rows for one payee, oldest first.
     *
     * @param  list<int>  $amountsSatang
     * @return \Illuminate\Support\Collection<int, CommissionLedger>
     */
    private function owe(Company $company, User $payee, array $amountsSatang): \Illuminate\Support\Collection
    {
        return collect($amountsSatang)->map(fn (int $satang) => CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $payee->id,
            'amount_satang' => $satang,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]));
    }

    private function notificationCount(User $user, NotificationType $type): int
    {
        return (int) DB::table('notifications')
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->count();
    }

    private function lastBody(User $user): string
    {
        return (string) DB::table('notifications')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->value('body');
    }
}
