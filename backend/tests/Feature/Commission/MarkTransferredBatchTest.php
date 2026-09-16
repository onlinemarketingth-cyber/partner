<?php

namespace Tests\Feature\Commission;

use App\Enums\IdDocumentType;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Models\CommissionLedger;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Company;
use App\Models\Notification as NotificationModel;
use App\Models\Product;
use App\Models\User;
use App\Services\Commission\CommissionWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-16 — บันทึกว่าโอนแล้วทั้งรอบในครั้งเดียว.
 *
 * Owner: "เราโอนเองผ่านระบบการทำงาน Bank … ต้องได้รับข้อมูลจากฝ่ายบัญชีก่อนว่า
 * โอนแล้วจึงมากดยืนยัน". Accounting works in rounds and reports back in batches,
 * so recording a round used to be ten presses and ten browser prompts, each
 * asking for the reference the admin had just typed.
 *
 * ── WHY ALL-OR-NOTHING MATTERS MORE HERE THAN ANYWHERE ELSE ──
 *
 * This is the press that settles commission_ledger rows AND emails agents that
 * their money has arrived. A half-finished batch is the worst outcome the
 * system can produce: some ledger rows closed, some open, some agents told,
 * some not, and no screen anywhere able to say which is which. Pressing again
 * then refuses the rows that already went through while letting the rest in,
 * so the second press is harder to reason about than the first.
 *
 * Every test below is a way that could happen.
 */
class MarkTransferredBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_settles_every_request_ticked(): void
    {
        [$company, $admin] = $this->world();
        $first = $this->raisedPayout($company, 120000);
        $second = $this->raisedPayout($company, 45050);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(
            [WithdrawalStatus::Transferred, WithdrawalStatus::Transferred],
            CommissionWithdrawalRequest::withoutGlobalScopes()->orderBy('id')->get()->map->status->all(),
        );
    }

    public function test_it_closes_the_ledger_rows_the_payouts_fully_covered(): void
    {
        /*
         * THE point of this press. Approving does not settle anything on
         * purpose (แนวทาง C) — the money has not moved. This is the only step
         * in the system that flips a commission_ledger row to Paid.
         */
        [$company, $admin] = $this->world();
        $payout = $this->raisedPayout($company, 120000);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$payout->id],
            ])
            ->assertOk();

        $this->assertSame(
            PaymentStatus::Paid,
            CommissionLedger::withoutGlobalScopes()->where('agent_id', $payout->agent_id)->sole()->payment_status,
        );
    }

    public function test_one_reference_is_written_onto_every_row_in_the_round(): void
    {
        // A round of transfers made from one bank file has ONE reference. Per
        // row would be the honest shape only if each row were sent separately.
        [$company, $admin] = $this->world();
        $first = $this->raisedPayout($company, 120000);
        $second = $this->raisedPayout($company, 45050);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$first->id, $second->id],
                'transfer_reference' => 'TRF-690916-01',
            ])
            ->assertOk();

        $this->assertSame(
            ['TRF-690916-01', 'TRF-690916-01'],
            CommissionWithdrawalRequest::withoutGlobalScopes()->orderBy('id')->pluck('transfer_reference')->all(),
        );
    }

    public function test_the_reference_stays_optional(): void
    {
        // Not every transfer produces one worth recording, and a required
        // field here only invites made-up values that look like evidence.
        [$company, $admin] = $this->world();
        $payout = $this->raisedPayout($company, 120000);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$payout->id],
            ])
            ->assertOk();

        $this->assertNull(CommissionWithdrawalRequest::withoutGlobalScopes()->sole()->transfer_reference);
        $this->assertNotNull(CommissionWithdrawalRequest::withoutGlobalScopes()->sole()->transferred_at);
    }

    public function test_a_row_that_is_not_approved_rolls_back_the_entire_round(): void
    {
        /*
         * THE ONE THAT MATTERS. The second request was rejected while the admin
         * was reading the screen. The first is perfectly transferable — and
         * must NOT be settled, because the admin confirmed one round against a
         * list, not two rounds they can no longer tell apart.
         */
        [$company, $admin] = $this->world();
        $fine = $this->raisedPayout($company, 120000);
        $stale = $this->raisedPayout($company, 45050);
        $stale->forceFill(['status' => WithdrawalStatus::PendingReview])->save();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$fine->id, $stale->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(
            WithdrawalStatus::Approved,
            CommissionWithdrawalRequest::withoutGlobalScopes()->find($fine->id)->status,
            'the transferable row was left alone',
        );
        $this->assertSame(
            PaymentStatus::Pending,
            CommissionLedger::withoutGlobalScopes()->where('agent_id', $fine->agent_id)->sole()->payment_status,
        );
    }

    public function test_nobody_is_emailed_about_a_round_that_was_rolled_back(): void
    {
        /*
         * CommissionPaid is the one notification in this flow with email
         * enabled. If it fired inside the transaction, the database would
         * forget the settlement and the agent's inbox would not — they would be
         * told their money had arrived when nothing moved and no record of the
         * claim exists anywhere in the system.
         */
        [$company, $admin] = $this->world();
        $fine = $this->raisedPayout($company, 120000);
        $stale = $this->raisedPayout($company, 45050);
        $stale->forceFill(['status' => WithdrawalStatus::Rejected])->save();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$fine->id, $stale->id],
            ])
            ->assertStatus(422);

        /*
         * Asserted on the app's OWN notifications table, not on
         * Notification::fake(): nothing here goes through Laravel's
         * notification facade — NotificationService writes a row and hands the
         * email to NotificationMailer — so a faked facade would assert nothing
         * was sent while the real rows piled up beside it.
         *
         * Narrowed to CommissionPaid rather than counting every row: raising
         * the two payouts in setUp legitimately announces "ตั้งจ่ายแล้ว" to
         * each agent, and a bare count(0) here fails on those — which is how
         * this assertion passed for the wrong reason in its first draft.
         * CommissionPaid is the "your money has arrived" email, and it is the
         * one that must not exist after a rolled-back round.
         */
        $this->assertSame(
            0,
            NotificationModel::withoutGlobalScopes()->where('type', NotificationType::CommissionPaid)->count(),
        );
    }

    public function test_everyone_whose_money_moved_is_told(): void
    {
        [$company, $admin] = $this->world();
        $first = $this->raisedPayout($company, 120000);
        $second = $this->raisedPayout($company, 45050);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$first->id, $second->id],
            ])
            ->assertOk();

        $told = NotificationModel::withoutGlobalScopes()
            ->where('type', NotificationType::CommissionPaid)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $first->agent_id, $told);
        $this->assertContains((int) $second->agent_id, $told);
    }

    public function test_the_same_request_twice_is_refused_rather_than_quietly_deduplicated(): void
    {
        [$company, $admin] = $this->world();
        $payout = $this->raisedPayout($company, 120000);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$payout->id, $payout->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['withdrawal_request_ids']);

        $this->assertSame(WithdrawalStatus::Approved, CommissionWithdrawalRequest::withoutGlobalScopes()->sole()->status);
    }

    public function test_another_companys_request_cannot_be_smuggled_into_the_list(): void
    {
        /*
         * BR-6. `exists:` in the form request proves the row is in the table
         * and nothing about whose it is — and this endpoint takes a list of raw
         * ids. The row is resolved through visibleTo(), so another tenant's id
         * comes back as "not found" rather than as a 403 that confirms it
         * exists.
         */
        [$company, $admin] = $this->world();
        [$other] = $this->world();
        $mine = $this->raisedPayout($company, 120000);
        $theirs = $this->raisedPayout($other, 90000);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$mine->id, $theirs->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['withdrawal_request_ids']);

        $this->assertSame(
            WithdrawalStatus::Approved,
            CommissionWithdrawalRequest::withoutGlobalScopes()->find($mine->id)->status,
        );
    }

    public function test_an_agent_cannot_press_it_for_their_own_payout(): void
    {
        [$company] = $this->world();
        $payout = $this->raisedPayout($company, 120000);
        $agent = User::withoutGlobalScopes()->find($payout->agent_id);

        $this->actingAs($agent)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => [$payout->id],
            ])
            ->assertForbidden();

        $this->assertSame(WithdrawalStatus::Approved, CommissionWithdrawalRequest::withoutGlobalScopes()->sole()->status);
    }

    public function test_an_empty_selection_is_refused(): void
    {
        [, $admin] = $this->world();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', ['withdrawal_request_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['withdrawal_request_ids']);
    }

    public function test_more_than_fifty_in_one_press_is_refused(): void
    {
        [$company, $admin] = $this->world();
        $payout = $this->raisedPayout($company, 120000);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/mark-transferred-batch', [
                'withdrawal_request_ids' => array_fill(0, 51, $payout->id),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['withdrawal_request_ids']);
    }

    /** @return array{0: Company, 1: User} */
    private function world(): array
    {
        $company = Company::factory()->create();

        return [$company, User::factory()->companyAdmin()->create(['company_id' => $company->id])];
    }

    /**
     * An agent owed exactly $satang, with a payout already raised for the whole
     * of it — i.e. a row sitting in ขั้นที่ 3, waiting for accounting.
     */
    private function raisedPayout(Company $company, int $satang): CommissionWithdrawalRequest
    {
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => '1234567890123',
            'id_document_type' => IdDocumentType::ThaiNationalId,
            'bank_name' => 'กสิกรไทย',
            'bank_account_number' => '1234567890',
            'bank_account_holder_name' => 'สมาชิก ทดสอบ',
        ]);

        CommissionLedger::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => Product::factory()->create(['company_id' => $company->id])->id,
            'rate_type_applied' => 'percentage',
            'rate_applied' => 500,
            'amount_satang' => $satang,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        return app(CommissionWithdrawalService::class)
            ->payOut($agent, $satang, $admin);
    }
}
