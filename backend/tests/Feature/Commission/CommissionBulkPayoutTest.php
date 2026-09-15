<?php

namespace Tests\Feature\Commission;

use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\User;
use App\Services\Commission\CommissionHouseAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-15 — "จ่ายทั้งหมดของคนนี้".
 *
 * Owner's request: a payout run is one person and a page of rows, and
 * pressing "จ่ายแล้ว" thirty times is thirty chances to stop halfway with no
 * record of where.
 *
 * A bulk money button is a different animal from thirty single ones, and
 * every test below is about one of the ways it could be worse rather than
 * better:
 *
 *   · IT MUST PAY WHAT THE ADMIN SAW, and nothing that arrived afterwards.
 *     BR-4 makes a ledger row immutable, so a row paid by accident here can
 *     never be un-paid — only explained. The total on screen is therefore
 *     part of the request and a disagreement aborts the whole run.
 *   · IT MUST LEAVE THE SAME TRAIL as one press. One audit row per ledger
 *     row, same action string, so "when was this row paid, and by whom" has
 *     one answer whichever button was used.
 *   · IT MUST NOT REACH FURTHER THAN THE PERSON NAMED — not other agents,
 *     not other companies, and not the company's own seat, which is money
 *     that never moves.
 */
class CommissionBulkPayoutTest extends TestCase
{
    use RefreshDatabase;

    // ── It settles the run ────────────────────────────────────────────

    public function test_a_company_admin_settles_every_pending_row_in_one_press(): void
    {
        [$company, $admin, $agent] = $this->world();

        $rows = $this->owe($company, $agent, [10000, 25000, 5000]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 40000,
            ])
            ->assertOk()
            ->assertJsonPath('data.paid_count', 3)
            ->assertJsonPath('data.paid_satang', 40000);

        foreach ($rows as $row) {
            $row->refresh();
            $this->assertSame('paid', $row->payment_status->value);
            $this->assertNotNull($row->paid_at);
        }
    }

    public function test_it_leaves_one_audit_row_per_ledger_row_under_one_batch(): void
    {
        /*
         * Per row, not per batch. `auditable_id` is what anybody
         * investigating a single payment searches on, and a batch-shaped log
         * answers "was this row paid, by whom" with a row id buried inside an
         * array. The batch id rides along so the run is still legible as one
         * act — which is the question the per-row shape cannot answer on its
         * own.
         */
        [$company, $admin, $agent] = $this->world();
        $rows = $this->owe($company, $agent, [10000, 25000]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 35000,
            ])
            ->assertOk();

        $logs = AuditLog::where('action', 'commission_ledger.marked_paid')->get();

        $this->assertCount(2, $logs);
        $this->assertSame(
            $rows->pluck('id')->sort()->values()->all(),
            $logs->pluck('auditable_id')->sort()->values()->all(),
        );
        $this->assertCount(1, $logs->pluck('new_values.batch_id')->unique());
        $this->assertNotNull($logs->first()->new_values['batch_id']);
        $this->assertSame($admin->id, $logs->first()->actor_user_id);
        // The amount travels with the status, same as the single-row log: an
        // audit entry that forces a join to learn what was paid is one people
        // stop reading.
        $this->assertSame(
            [10000, 25000],
            $logs->pluck('new_values.amount_satang')->sort()->values()->all(),
        );
    }

    public function test_the_action_string_is_the_same_one_a_single_press_writes(): void
    {
        /*
         * The tripwire on "the bulk endpoint is a second implementation".
         * Both paths now go through CommissionPayoutService; if one ever
         * grows its own audit shape, an auditor filtering by action would
         * silently see half the payouts.
         */
        [$company, $admin, $agent] = $this->world();
        $single = $this->owe($company, $agent, [10000])->first();
        $bulk = $this->owe($company, $agent, [25000])->first();

        $this->actingAs($admin)->postJson("/api/v1/commission-ledger/{$single->id}/mark-paid")->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/commission-ledger/mark-paid', [
            'agent_id' => $agent->id,
            'expected_total_satang' => 25000,
        ])->assertOk();

        $this->assertSame(
            [$single->id, $bulk->id],
            AuditLog::where('action', 'commission_ledger.marked_paid')
                ->pluck('auditable_id')->sort()->values()->all(),
        );
    }

    public function test_it_notifies_the_agent_once_and_not_once_per_row(): void
    {
        // Thirty rows is one payout to one person. Thirty messages about it
        // is a notification list nobody opens again.
        [$company, $admin, $agent] = $this->world();
        $this->owe($company, $agent, [10000, 25000, 5000]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 40000,
            ])
            ->assertOk();

        $this->assertSame(1, \DB::table('notifications')
            ->where('user_id', $agent->id)
            ->where('type', NotificationType::CommissionPaid->value)
            ->count());
    }

    // ── It pays what was on screen, and nothing else ──────────────────

    public function test_a_row_that_arrived_after_the_screen_loaded_aborts_the_whole_run(): void
    {
        /*
         * THE ONE THAT MATTERS. Between the screen loading and the button
         * being pressed, a sale can complete and write a new pending row.
         * Without the total check the admin's press would pay it too — for an
         * amount they never saw, in their name, permanently (BR-4).
         *
         * Nothing at all is written: the refusal happens inside the
         * transaction, so the rows the admin DID intend to pay are not
         * half-settled either.
         */
        [$company, $admin, $agent] = $this->world();
        $rows = $this->owe($company, $agent, [10000, 25000]);
        $lateArrival = $this->owe($company, $agent, [99900])->first();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $agent->id,
                // What the screen was showing before the late row landed.
                'expected_total_satang' => 35000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_total_satang');

        foreach ($rows->push($lateArrival) as $row) {
            $this->assertSame('pending', $row->refresh()->payment_status->value);
        }
        $this->assertSame(0, AuditLog::where('action', 'commission_ledger.marked_paid')->count());
    }

    public function test_the_date_range_on_screen_bounds_what_is_paid(): void
    {
        /*
         * The button must settle exactly the set the total above it was
         * computed from. The summary endpoint filters on created_at with the
         * same whereDate shape, so a row outside the range was never in the
         * number the admin approved.
         */
        [$company, $admin, $agent] = $this->world();

        $inside = $this->owe($company, $agent, [10000])->first();
        // Dated at CREATION, never by updating afterwards: `created_at` is not
        // in CommissionLedger::MUTABLE_AFTER_CREATION and the model throws on
        // any attempt to move it (BR-4). The refusal is right, and it is why
        // this fixture is shaped the way it is.
        $outside = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'amount_satang' => 50000,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
            'created_at' => now()->subMonths(2),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $agent->id,
                'date_from' => now()->subDays(7)->toDateString(),
                'expected_total_satang' => 10000,
            ])
            ->assertOk()
            ->assertJsonPath('data.paid_count', 1);

        $this->assertSame('paid', $inside->refresh()->payment_status->value);
        $this->assertSame('pending', $outside->refresh()->payment_status->value);
    }

    public function test_an_already_paid_row_keeps_the_date_it_was_actually_paid(): void
    {
        /*
         * Re-stamping moves paid_at to today and writes a second audit row
         * for a transfer that happened last month. The run simply does not
         * see settled rows — and because it does not see them, their amount
         * is not in the total either, which is why the expected total below
         * is 10000 and not 60000.
         */
        [$company, $admin, $agent] = $this->world();
        $settled = $this->owe($company, $agent, [50000])->first();
        $settled->forceFill([
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now()->subMonth(),
        ])->save();
        $stillOwed = $this->owe($company, $agent, [10000])->first();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 10000,
            ])
            ->assertOk()
            ->assertJsonPath('data.paid_count', 1);

        $this->assertTrue($settled->refresh()->paid_at->isBefore(now()->subWeek()));
        $this->assertSame('paid', $stillOwed->refresh()->payment_status->value);
    }

    public function test_a_run_for_nothing_is_refused_rather_than_succeeding_emptily(): void
    {
        // The screen hides the button when nothing is owed, so a request that
        // arrives anyway is a stale tab — exactly the case the total check is
        // here to catch. "Succeeded, paid 0" would tell that tab it was right.
        [$company, $admin, $agent] = $this->world();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_total_satang');
    }

    // ── It reaches no further than the person named ───────────────────

    public function test_it_leaves_a_colleagues_rows_alone(): void
    {
        [$company, $admin, $agent] = $this->world();
        $colleague = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->owe($company, $agent, [10000]);
        $theirs = $this->owe($company, $colleague, [77000])->first();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 10000,
            ])
            ->assertOk();

        $this->assertSame('pending', $theirs->refresh()->payment_status->value);
    }

    public function test_a_company_admin_may_not_settle_another_companys_agent(): void
    {
        [, $admin] = $this->world();
        $otherCompany = Company::factory()->create();
        $stranger = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $theirs = $this->owe($otherCompany, $stranger, [10000])->first();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $stranger->id,
                'expected_total_satang' => 10000,
            ])
            ->assertForbidden();

        $this->assertSame('pending', $theirs->refresh()->payment_status->value);
    }

    public function test_an_agent_may_not_settle_their_own_commission(): void
    {
        // The self-dealing gap the single-row policy has always closed. The
        // bulk route asks the SAME policy about the payee instead of a row —
        // one predicate, so loosening one cannot quietly leave the other.
        [$company, , $agent] = $this->world();
        $rows = $this->owe($company, $agent, [10000]);

        $this->actingAs($agent)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 10000,
            ])
            ->assertForbidden();

        $this->assertSame('pending', $rows->first()->refresh()->payment_status->value);
    }

    public function test_the_companys_own_seat_cannot_be_a_bulk_payee(): void
    {
        /*
         * The seat's money is already with the company. "จ่ายแล้ว" on it
         * records the company transferring to itself, and BR-4 means nobody
         * can take that record back. The screen has no button for it; this is
         * the guard for everything that is not the screen.
         */
        [$company, $admin] = $this->world();
        $house = app(CommissionHouseAccountService::class)->create($company);
        $row = $this->owe($company, $house, [44850])->first();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [
                'agent_id' => $house->id,
                'expected_total_satang' => 44850,
            ])
            ->assertStatus(422);

        $this->assertSame('pending', $row->refresh()->payment_status->value);
    }

    public function test_the_companys_own_row_is_refused_on_the_single_endpoint_too(): void
    {
        // Same rule, other door. The UI hides the button on both screens; a
        // guard that lives only in the UI is one a stale tab walks past.
        [$company, $admin] = $this->world();
        $house = app(CommissionHouseAccountService::class)->create($company);
        $row = $this->owe($company, $house, [44850])->first();

        $this->actingAs($admin)
            ->postJson("/api/v1/commission-ledger/{$row->id}/mark-paid")
            ->assertStatus(422);

        $this->assertSame('pending', $row->refresh()->payment_status->value);
    }

    public function test_mark_paid_is_not_read_as_a_ledger_id(): void
    {
        /*
         * Route-order tripwire. Registered after the apiResource, the literal
         * "mark-paid" would be bound as {commission_ledger} and answer 404
         * from the model binder — a 404 that looks like a missing row rather
         * than a missing route, which is the kind of thing that gets
         * diagnosed for an hour.
         */
        [, $admin] = $this->world();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-ledger/mark-paid', [])
            ->assertStatus(422);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    /** @return array{0: Company, 1: User, 2: User} company, its admin, one agent */
    private function world(): array
    {
        $company = Company::factory()->create();

        return [
            $company,
            User::factory()->companyAdmin()->create(['company_id' => $company->id]),
            User::factory()->agent()->create(['company_id' => $company->id]),
        ];
    }

    /**
     * Pending ledger rows for one payee.
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
}
