<?php

namespace Tests\Feature\Account;

use App\Enums\AccountDeletionRequestStatus;
use App\Enums\AccountDeletionResolution;
use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Models\AccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionWithdrawalItem;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Company;
use App\Models\Referral;
use App\Models\User;
use App\Services\Account\AccountDeletionImpactService;
use App\Services\Commission\CommissionWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MOB-12 follow-up (owner decision 2026-10-03) — when deleting an account does
 * NOT wait for an admin.
 *
 *   * nothing unpaid               → deleted immediately (200 `deleted`);
 *   * unpaid + waive               → the unpaid rows become `forfeited` and
 *                                    the account is deleted immediately;
 *   * unpaid + keep                → the admin-decided request (202), pinned
 *                                    in AccountDeletionRequestTest;
 *   * unpaid + no choice           → 422, nothing changes;
 *   * waive while money is in a payout someone is working on → refused.
 *
 * And the part that is easy to break later: a forfeited row is neither owed
 * nor paid, so every payable / owed figure this change touched must leave it
 * out.
 */
class AccountDeletionImmediateTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private Company $company;

    private User $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->agent = User::factory()->agent()->create([
            'company_id' => $this->company->id,
            'password' => Hash::make(self::PASSWORD),
            'phone' => '0899999999',
        ]);
        $this->admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
    }

    private function owe(User $agent, int $satang, PaymentStatus $status = PaymentStatus::Pending): CommissionLedger
    {
        $this->app['auth']->forgetGuards();
        $client = Client::factory()->create(['company_id' => $agent->company_id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::factory()->create(['client_id' => $client->id]);

        return CommissionLedger::factory()->create([
            'referral_id' => $referral->id,
            'amount_satang' => $satang,
            'payment_status' => $status,
            'paid_at' => $status === PaymentStatus::Paid ? now() : null,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function ask(User $user, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->postJson('/api/v1/me/account-deletion-request', ['password' => self::PASSWORD] + $extra);
    }

    private function preview(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->getJson('/api/v1/me/account-deletion-request/preview');
    }

    private function openWithdrawal(User $agent, CommissionLedger $row, WithdrawalStatus $status, int $allocated): CommissionWithdrawalRequest
    {
        $request = CommissionWithdrawalRequest::withoutGlobalScopes()->create([
            'company_id' => $agent->company_id,
            'agent_id' => $agent->id,
            'amount_satang' => $allocated,
            'status' => $status,
        ]);
        CommissionWithdrawalItem::create([
            'commission_withdrawal_request_id' => $request->id,
            'commission_ledger_id' => $row->id,
            'allocated_satang' => $allocated,
        ]);

        return $request;
    }

    /** @return list<array<string, mixed>> */
    private function ledgerSnapshot(): array
    {
        return DB::table('commission_ledger')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function freshUser(User $user): User
    {
        return User::withTrashed()->withoutGlobalScopes()->findOrFail($user->id);
    }

    // ── preview ──────────────────────────────────────────────────────────

    public function test_preview_reports_nothing_owed(): void
    {
        $this->owe($this->agent, 4_000, PaymentStatus::Paid);

        $this->preview($this->agent)
            ->assertOk()
            ->assertExactJson(['data' => [
                'pending_commission_satang' => 0,
                'can_waive' => true,
                'waive_blocked_reason' => null,
            ]]);
    }

    public function test_preview_reports_the_unpaid_figure_the_admin_queue_shows(): void
    {
        $this->owe($this->agent, 12_000);
        $this->owe($this->agent, 3_450);
        $this->owe($this->agent, 9_999, PaymentStatus::Paid);

        $this->preview($this->agent)
            ->assertOk()
            ->assertJsonPath('data.pending_commission_satang', 15_450)
            ->assertJsonPath('data.can_waive', true);

        $this->actingAs($this->agent);
        $this->assertSame(15_450, app(AccountDeletionImpactService::class)->forUser($this->agent)['pending_commission_satang']);
    }

    public function test_preview_refuses_waive_while_a_payout_is_open(): void
    {
        $row = $this->owe($this->agent, 12_000);
        $this->openWithdrawal($this->agent, $row, WithdrawalStatus::PendingReview, 12_000);

        $this->preview($this->agent)
            ->assertOk()
            ->assertJsonPath('data.pending_commission_satang', 12_000)
            ->assertJsonPath('data.can_waive', false)
            ->assertJsonPath('data.waive_blocked_reason', fn ($reason) => str_contains($reason, 'ต้องการรับค่าคอม'));
    }

    public function test_preview_is_for_agents_only(): void
    {
        $this->preview($this->admin)->assertForbidden();
    }

    // ── nothing owed → immediate ─────────────────────────────────────────

    public function test_an_agent_who_owes_nothing_is_deleted_immediately(): void
    {
        $paid = $this->owe($this->agent, 4_000, PaymentStatus::Paid);
        $recruit = User::factory()->agent()->create(['company_id' => $this->company->id, 'manager_id' => $this->agent->id]);
        $this->agent->createToken('agent-portal');
        $before = $this->ledgerSnapshot();
        $oldEmail = $this->agent->email;

        // A choice sent anyway is ignored, not an error.
        $this->ask($this->agent, ['commission_choice' => 'waive', 'reason' => 'พอแล้ว'])
            ->assertOk()
            ->assertJsonPath('data.status', 'deleted')
            ->assertJsonPath('data.forfeited_commission_satang', null)
            ->assertJsonStructure(['data' => ['status', 'requested_at', 'deleted_at', 'forfeited_commission_satang']]);

        $request = AccountDeletionRequest::withoutGlobalScopes()->where('user_id', $this->agent->id)->sole();
        $this->assertSame(AccountDeletionRequestStatus::Approved, $request->status);
        $this->assertSame(AccountDeletionResolution::Immediate, $request->resolution);
        $this->assertNull($request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertSame('ลบทันที: ไม่มีค่าคอมค้างจ่าย', $request->decision_note);
        $this->assertNull($request->forfeited_commission_satang);
        $this->assertSame('พอแล้ว', $request->reason);

        $user = $this->freshUser($this->agent);
        $this->assertTrue($user->trashed());
        $this->assertSame('บัญชีที่ถูกลบ #'.$this->agent->id, $user->name);
        $this->assertNull($user->phone);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $this->agent->id)->count());

        // Ledger untouched; the downline stays attached until an admin moves it.
        $this->assertSame($before, $this->ledgerSnapshot());
        $this->assertSame(PaymentStatus::Paid, $paid->fresh()->payment_status);
        $this->assertSame($this->agent->id, $recruit->fresh()->manager_id);

        $this->assertSame(1, AuditLog::where('action', 'account_deletion.requested')->count());
        $approved = AuditLog::where('action', 'account_deletion.approved')->sole();
        $this->assertSame($this->agent->id, $approved->actor_user_id);
        $this->assertSame('immediate', $approved->new_values['resolution']);
        $this->assertSame(0, AuditLog::where('action', 'account_deletion.commission_forfeited')->count());

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Auth-Mode', 'token')
            ->postJson('/api/v1/login', ['email' => $oldEmail, 'password' => self::PASSWORD])
            ->assertStatus(422);
    }

    // ── owed → a choice is required ──────────────────────────────────────

    public function test_a_choice_is_required_when_commission_is_unpaid_and_nothing_changes_without_one(): void
    {
        $this->owe($this->agent, 12_345);
        $this->agent->createToken('agent-portal');

        $this->ask($this->agent)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commission_choice'])
            ->assertJsonPath('errors.commission_choice.0', fn ($m) => str_contains($m, '123.45 บาท'));

        $this->ask($this->agent, ['commission_choice' => 'maybe'])->assertStatus(422);

        $this->assertSame(0, AccountDeletionRequest::withoutGlobalScopes()->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $this->agent->id)->count());
        $this->assertFalse($this->freshUser($this->agent)->trashed());
    }

    public function test_keep_files_the_admin_decided_request(): void
    {
        $row = $this->owe($this->agent, 12_000);

        $this->ask($this->agent, ['commission_choice' => 'keep'])
            ->assertStatus(202)
            ->assertExactJson(['data' => [
                'status' => 'pending',
                'requested_at' => AccountDeletionRequest::withoutGlobalScopes()->sole()->requested_at->toIso8601String(),
            ]]);

        $this->assertSame(PaymentStatus::Pending, $row->fresh()->payment_status);
        $this->assertFalse($this->freshUser($this->agent)->trashed());
        $this->assertNull(AccountDeletionRequest::withoutGlobalScopes()->sole()->resolution);
    }

    // ── waive ────────────────────────────────────────────────────────────

    public function test_waive_forfeits_exactly_the_unpaid_rows_and_deletes_immediately(): void
    {
        $unpaidA = $this->owe($this->agent, 12_000);
        $unpaidB = $this->owe($this->agent, 3_450);
        $paid = $this->owe($this->agent, 9_999, PaymentStatus::Paid);
        $colleague = User::factory()->agent()->create(['company_id' => $this->company->id]);
        $colleagueRow = $this->owe($colleague, 5_000);
        $before = collect($this->ledgerSnapshot())->keyBy('id');

        $this->ask($this->agent, ['commission_choice' => 'waive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'deleted')
            ->assertJsonPath('data.forfeited_commission_satang', 15_450);

        $after = collect($this->ledgerSnapshot())->keyBy('id');

        foreach ([$unpaidA, $unpaidB] as $row) {
            $this->assertSame('forfeited', $after[$row->id]['payment_status']);
            $this->assertNull($after[$row->id]['paid_at']);
            // BR-4: every column but payment_status/updated_at is as written.
            $this->assertSame(
                collect($before[$row->id])->except(['payment_status', 'updated_at'])->all(),
                collect($after[$row->id])->except(['payment_status', 'updated_at'])->all(),
            );
        }
        // Rows that were not this agent's unpaid commission are byte-identical.
        foreach ([$paid, $colleagueRow] as $row) {
            $this->assertSame($before[$row->id], $after[$row->id]);
        }

        $request = AccountDeletionRequest::withoutGlobalScopes()->where('user_id', $this->agent->id)->sole();
        $this->assertSame(AccountDeletionResolution::Immediate, $request->resolution);
        $this->assertSame(15_450, $request->forfeited_commission_satang);
        $this->assertSame('ลบทันที: สมาชิกยินยอมสละค่าคอมค้างจ่าย ฿154.50', $request->decision_note);
        $this->assertTrue($this->freshUser($this->agent)->trashed());

        $audit = AuditLog::where('action', 'account_deletion.commission_forfeited')->sole();
        $this->assertSame($this->agent->id, $audit->actor_user_id);
        $this->assertSame(15_450, $audit->new_values['forfeited_satang']);
        $this->assertEqualsCanonicalizing([$unpaidA->id, $unpaidB->id], $audit->new_values['commission_ledger_ids']);
        $this->assertSame(1, AuditLog::where('action', 'account_deletion.approved')->count());
    }

    public function test_waive_is_refused_while_a_payout_is_open_and_keep_still_works(): void
    {
        $row = $this->owe($this->agent, 12_000);
        $this->openWithdrawal($this->agent, $row, WithdrawalStatus::Approved, 12_000);
        $before = $this->ledgerSnapshot();

        $this->ask($this->agent, ['commission_choice' => 'waive'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'ต้องการรับค่าคอม'));

        $this->assertSame($before, $this->ledgerSnapshot());
        $this->assertSame(0, AccountDeletionRequest::withoutGlobalScopes()->count());
        $this->assertFalse($this->freshUser($this->agent)->trashed());

        $this->ask($this->agent, ['commission_choice' => 'keep'])->assertStatus(202);
    }

    public function test_waive_is_refused_when_an_unpaid_row_was_already_partly_paid(): void
    {
        $row = $this->owe($this->agent, 12_000);
        $this->openWithdrawal($this->agent, $row, WithdrawalStatus::Transferred, 5_000);

        $this->preview($this->agent)->assertJsonPath('data.can_waive', false);
        $this->ask($this->agent, ['commission_choice' => 'waive'])->assertStatus(409);

        $this->assertSame(PaymentStatus::Pending, $row->fresh()->payment_status);
    }

    // ── a forfeited row is neither owed nor payable ──────────────────────

    public function test_forfeited_rows_leave_every_payable_and_owed_figure(): void
    {
        $row = $this->owe($this->agent, 12_000);
        $this->ask($this->agent, ['commission_choice' => 'waive'])->assertOk();

        $agent = $this->freshUser($this->agent);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin);

        // Withdrawal balance and the deletion warning count.
        $this->assertSame(0, app(CommissionWithdrawalService::class)->availableSatang($agent));
        $this->assertSame(0, app(AccountDeletionImpactService::class)->forUser($agent)['pending_commission_satang']);

        // The payout queue (owed per agent) no longer lists them.
        $owed = collect($this->getJson('/api/v1/agent-commission-summary?payment_status=pending')->assertOk()->json('data'));
        $this->assertNull($owed->firstWhere('agent_id', $agent->id));

        // Settling it by hand is refused, and the row stays forfeited.
        $this->postJson("/api/v1/commission-ledger/{$row->id}/mark-paid")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commission_ledger']);
        $this->assertSame(PaymentStatus::Forfeited, $row->fresh()->payment_status);

        // The ledger list reports it as what it is.
        $this->getJson("/api/v1/commission-ledger/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'forfeited');
    }

    // ── the admin queue ──────────────────────────────────────────────────

    public function test_the_admin_queue_shows_immediate_deletions_and_keeps_tenants_apart(): void
    {
        $this->owe($this->agent, 15_450);
        $this->ask($this->agent, ['commission_choice' => 'waive'])->assertOk();

        $other = Company::factory()->create();
        $otherAdmin = User::factory()->companyAdmin()->create(['company_id' => $other->id]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->getJson('/api/v1/account-deletion-requests?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.resolution', 'immediate')
            ->assertJsonPath('data.0.forfeited_commission_satang', 15_450)
            ->assertJsonPath('data.0.decided_by', null)
            ->assertJsonPath('data.0.pending_commission_satang', 0);

        $this->actingAs($otherAdmin)
            ->getJson('/api/v1/account-deletion-requests?status=approved')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
