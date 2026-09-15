<?php

namespace Tests\Feature\Commission;

use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Models\CommissionLedger;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Company;
use App\Models\Notification as NotificationModel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-15 — ตั้งจ่ายหลายคนในครั้งเดียว (แบบ C).
 *
 * Owner picked the checkbox table: tick the people to pay this round, press
 * once. The press is what this file is about, and the single property that
 * makes the shape safe is that it is ALL OR NOTHING.
 *
 * ── WHY THAT PROPERTY IS THE WHOLE POINT ──
 *
 * The obvious way to build this is a loop in the browser over the endpoint
 * that already exists. Then the third of five payees fails — a sale completed
 * while the admin was reading, or somebody has no bank account — and two
 * payouts are raised, three are not, and the screen has no way to say which.
 * Press again and the two already raised are refused as stale while the other
 * three go through, so the second press is more confusing than the first.
 *
 * Every test below is a way that could happen.
 */
class CompanyPayoutBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_raises_one_payout_per_person_ticked(): void
    {
        [$company, $admin] = $this->world();
        $first = $this->agentOwed($company, 120000);
        $second = $this->agentOwed($company, 45050);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [
                    ['agent_id' => $first->id, 'expected_total_satang' => 120000],
                    ['agent_id' => $second->id, 'expected_total_satang' => 45050],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, CommissionWithdrawalRequest::withoutGlobalScopes()->count());
        $this->assertSame(
            [45050, 120000],
            CommissionWithdrawalRequest::withoutGlobalScopes()->orderBy('amount_satang')->pluck('amount_satang')->map(fn ($v) => (int) $v)->all(),
        );
    }

    public function test_every_payout_starts_approved_and_leaves_the_ledger_alone(): void
    {
        /*
         * The same rule the single door follows, restated here because a batch
         * is where somebody would be tempted to "just settle them all" — the
         * company transfers by hand and confirms days later, so the ledger
         * cannot move until the transfer is recorded (แนวทาง C).
         */
        [$company, $admin] = $this->world();
        $agent = $this->agentOwed($company, 90000);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [['agent_id' => $agent->id, 'expected_total_satang' => 90000]],
            ])
            ->assertCreated();

        $this->assertSame(WithdrawalStatus::Approved, CommissionWithdrawalRequest::withoutGlobalScopes()->sole()->status);
        $this->assertSame(
            PaymentStatus::Pending,
            CommissionLedger::withoutGlobalScopes()->where('agent_id', $agent->id)->sole()->payment_status,
        );
    }

    public function test_one_stale_row_rolls_back_the_entire_press(): void
    {
        /*
         * THE ONE THAT MATTERS. The second payee's balance moved while the
         * admin was reading the screen. The first payee is perfectly payable —
         * and must NOT be paid, because the admin authorised one press over a
         * list, not two presses they can no longer tell apart.
         */
        [$company, $admin] = $this->world();
        $fine = $this->agentOwed($company, 120000);
        $moved = $this->agentOwed($company, 45050);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [
                    ['agent_id' => $fine->id, 'expected_total_satang' => 120000],
                    ['agent_id' => $moved->id, 'expected_total_satang' => 30000],
                ],
            ])
            ->assertStatus(422)
            // Keyed by POSITION, so the screen can mark the row that caused it
            // rather than printing a sentence over the whole table.
            ->assertJsonValidationErrors(['payees.1.expected_total_satang']);

        $this->assertSame(0, CommissionWithdrawalRequest::withoutGlobalScopes()->count(), 'nothing at all was written');
    }

    public function test_the_refusal_names_the_person_it_is_about(): void
    {
        // On a list of ten, "ยอดค้างจ่ายของคนนี้เปลี่ยนไปแล้ว" with no name is a
        // sentence the admin cannot act on.
        [$company, $admin] = $this->world();
        $agent = $this->agentOwed($company, 45050, name: 'สมหญิง ใจดี');

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [['agent_id' => $agent->id, 'expected_total_satang' => 30000]],
            ])
            ->assertStatus(422);

        // Indexed off the errors array by its LITERAL key — json('errors.payees.0…')
        // reads the dots as nesting and finds nothing, which is how the first
        // draft of this test passed a null into assertStringContainsString.
        $errors = $response->json('errors');

        $this->assertStringContainsString('สมหญิง ใจดี', $errors['payees.0.expected_total_satang'][0]);
    }

    public function test_a_payee_with_no_bank_account_rolls_back_the_press_too(): void
    {
        [$company, $admin] = $this->world();
        $fine = $this->agentOwed($company, 120000);
        $unbanked = $this->agentOwed($company, 80000, payable: false);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [
                    ['agent_id' => $fine->id, 'expected_total_satang' => 120000],
                    ['agent_id' => $unbanked->id, 'expected_total_satang' => 80000],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payees.1.expected_total_satang']);

        $this->assertSame(0, CommissionWithdrawalRequest::withoutGlobalScopes()->count());
    }

    public function test_nobody_is_emailed_about_a_payout_that_was_rolled_back(): void
    {
        /*
         * open() announces as its last act, which inside a batch happens while
         * the outer transaction is still open. If the batch then fails, the
         * database forgets the payout — and an email does not. This is why
         * payOutMany() holds the announcements until after the commit.
         */
        [$company, $admin] = $this->world();
        $fine = $this->agentOwed($company, 120000);
        $moved = $this->agentOwed($company, 45050);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [
                    ['agent_id' => $fine->id, 'expected_total_satang' => 120000],
                    ['agent_id' => $moved->id, 'expected_total_satang' => 30000],
                ],
            ])
            ->assertStatus(422);

        /*
         * Asserted on the app's OWN notifications table, not on
         * Notification::fake(): nothing here goes through Laravel's
         * notification facade — NotificationService writes a row and hands the
         * email to NotificationMailer — so a faked facade would assert nothing
         * was sent while the real rows piled up beside it.
         */
        $this->assertSame(0, NotificationModel::withoutGlobalScopes()->count());
    }

    public function test_everyone_paid_is_told(): void
    {
        [$company, $admin] = $this->world();
        $first = $this->agentOwed($company, 120000);
        $second = $this->agentOwed($company, 45050);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [
                    ['agent_id' => $first->id, 'expected_total_satang' => 120000],
                    ['agent_id' => $second->id, 'expected_total_satang' => 45050],
                ],
            ])
            ->assertCreated();

        $told = NotificationModel::withoutGlobalScopes()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($first->id, $told);
        $this->assertContains($second->id, $told);
    }

    public function test_the_same_person_twice_is_refused_rather_than_quietly_deduplicated(): void
    {
        /*
         * A list naming somebody twice is a screen that lost track of its own
         * selection. Paying them once hides that; paying twice would raise a
         * second payout against a balance the first already reserved.
         */
        [$company, $admin] = $this->world();
        $agent = $this->agentOwed($company, 120000);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [
                    ['agent_id' => $agent->id, 'expected_total_satang' => 120000],
                    ['agent_id' => $agent->id, 'expected_total_satang' => 120000],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payees']);

        $this->assertSame(0, CommissionWithdrawalRequest::withoutGlobalScopes()->count());
    }

    public function test_another_companys_agent_cannot_be_smuggled_into_the_list(): void
    {
        /*
         * BR-6, asked per row BEFORE the transaction opens. An id in a list of
         * fifty is the easiest place to hide one — and a 403 raised halfway
         * through the write is not one of the refusals the transaction unwinds
         * cleanly.
         */
        [$company, $admin] = $this->world();
        [$other] = $this->world();
        $mine = $this->agentOwed($company, 120000);
        $theirs = $this->agentOwed($other, 90000);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [
                    ['agent_id' => $mine->id, 'expected_total_satang' => 120000],
                    ['agent_id' => $theirs->id, 'expected_total_satang' => 90000],
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, CommissionWithdrawalRequest::withoutGlobalScopes()->count());
    }

    public function test_an_agent_cannot_press_it_for_anybody(): void
    {
        [$company] = $this->world();
        $agent = $this->agentOwed($company, 120000);

        $this->actingAs($agent)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', [
                'payees' => [['agent_id' => $agent->id, 'expected_total_satang' => 120000]],
            ])
            ->assertForbidden();
    }

    public function test_an_empty_selection_is_refused(): void
    {
        [, $admin] = $this->world();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', ['payees' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payees']);
    }

    public function test_more_than_fifty_in_one_press_is_refused(): void
    {
        // The cap is about how long one transaction holds fifty row locks, so
        // it is checked before any of them are taken.
        [$company, $admin] = $this->world();
        $agent = $this->agentOwed($company, 120000);

        $payees = array_fill(0, 51, ['agent_id' => $agent->id, 'expected_total_satang' => 120000]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout-batch', ['payees' => $payees])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payees']);
    }

    /** @return array{0: Company, 1: User} */
    private function world(): array
    {
        $company = Company::factory()->create();

        return [$company, User::factory()->companyAdmin()->create(['company_id' => $company->id])];
    }

    /**
     * An agent who is owed exactly $satang and, unless told otherwise, can be
     * paid it.
     */
    private function agentOwed(Company $company, int $satang, ?string $name = null, bool $payable = true): User
    {
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => $name !== null ? explode(' ', $name)[0] : 'ตัวแทน',
            'last_name' => $name !== null ? (explode(' ', $name)[1] ?? '') : 'ทดสอบ',
            ...($payable ? [
                'national_id' => '1234567890123',
                'id_document_type' => \App\Enums\IdDocumentType::ThaiNationalId,
                'bank_name' => 'กสิกรไทย',
                'bank_account_number' => '1234567890',
                'bank_account_holder_name' => 'ตัวแทน ทดสอบ',
            ] : []),
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

        return $agent;
    }
}
