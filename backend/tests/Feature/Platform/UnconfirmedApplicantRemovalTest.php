<?php

namespace Tests\Feature\Platform;

use App\Enums\AgentApprovalStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-08 (human: "อยากทำ soft delete ในการลบผู้สมัคร ที่ยังไม่ยืนยัน
 * ด้วยสิทธิ์ Super Admin และ Admin Company").
 *
 * The soft delete already existed — DELETE /users/{id} has moved `deleted_at`,
 * revoked tokens and written an audit row since TASK-183. What did not exist
 * was any way to reach it from the screen where the junk sign-ups are:
 * the roster row offered only "แก้ไข", and the deactivate control lived five
 * sections down inside the edit modal, next to controls meant for a trading
 * agent.
 *
 * So the work here is the QUESTION the screen could not answer: which of these
 * rows is a sign-up that never completed, and is therefore safe to clear out?
 *
 * `is_unconfirmed_applicant` is that answer, computed on the server beside the
 * login gate it mirrors. The tests below are mostly about what it must NOT
 * say yes to — every false positive here is a delete button offered over a
 * real agent's name.
 */
class UnconfirmedApplicantRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $genesenn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->genesenn = Company::factory()->create(['name' => 'GENESENN']);
    }

    /** A self-registered agent: came in through a company invite code. */
    private function applicant(array $attributes = []): User
    {
        $code = CompanyInviteCode::factory()->create(['company_id' => $this->genesenn->id]);

        return User::factory()->agent()->create(array_merge([
            'company_id' => $this->genesenn->id,
            'agent_approval_status' => AgentApprovalStatus::Pending,
            'email_verified_at' => null,
            'registered_via_invite_code_id' => $code->id,
        ], $attributes));
    }

    // ── Who counts as an unfinished sign-up ──────────────────────────

    public function test_an_applicant_waiting_for_approval_counts(): void
    {
        // The two rows in the human's screenshot: "รออนุมัติ (สมัครผ่าน อีเมล)".
        $this->assertTrue($this->applicant()->isUnconfirmedApplicant());
    }

    public function test_a_rejected_applicant_counts(): void
    {
        // A decision already made; nothing they do changes it, so the row is
        // there to be cleared rather than kept.
        $this->assertTrue($this->applicant([
            'agent_approval_status' => AgentApprovalStatus::Rejected,
            'email_verified_at' => now(),
        ])->isUnconfirmedApplicant());
    }

    public function test_an_approved_applicant_who_never_confirmed_their_address_counts(): void
    {
        // LoginGateService refuses them on EmailUnverified, so approval alone
        // never let them in.
        $this->assertTrue($this->applicant([
            'agent_approval_status' => AgentApprovalStatus::Approved,
            'email_verified_at' => null,
        ])->isUnconfirmedApplicant());
    }

    // ── Who must never count ─────────────────────────────────────────

    public function test_a_working_agent_does_not_count(): void
    {
        // The whole point of the flag. This person has a downline, orders and
        // commission behind them; removing them is a different decision and
        // keeps its own control.
        $this->assertFalse($this->applicant([
            'agent_approval_status' => AgentApprovalStatus::Approved,
            'email_verified_at' => now(),
        ])->isUnconfirmedApplicant());
    }

    public function test_an_admin_created_agent_with_no_verified_address_does_not_count(): void
    {
        /*
         * The trap. An Admin-created agent has `email_verified_at` null by
         * design and logs in perfectly well — LoginGateService scopes the
         * verification gate to self-registration for exactly this reason. A
         * flag that keyed on the timestamp alone would offer to delete every
         * agent an admin had ever added by hand.
         */
        $agent = User::factory()->agent()->create([
            'company_id' => $this->genesenn->id,
            'agent_approval_status' => AgentApprovalStatus::Approved,
            'email_verified_at' => null,
            'registered_via_invite_code_id' => null,
            'recruited_via_agent_link_id' => null,
        ]);

        $this->assertFalse($agent->isSelfRegistered());
        $this->assertFalse($agent->isUnconfirmedApplicant());
    }

    public function test_switching_a_company_off_does_not_turn_its_whole_team_into_applicants(): void
    {
        /*
         * The disaster this flag is deliberately narrower than the login gate
         * to avoid. LoginGateService refuses on FOUR grounds and the fourth is
         * about the COMPANY. Folding it in would relabel every agent inside a
         * deactivated tenant as an unfinished sign-up, and the roster would
         * start offering to remove the entire trading team of a company
         * somebody had merely switched off for a week.
         */
        $agent = $this->applicant([
            'agent_approval_status' => AgentApprovalStatus::Approved,
            'email_verified_at' => now(),
        ]);

        $this->genesenn->update(['is_active' => false]);

        $this->assertFalse($agent->refresh()->isUnconfirmedApplicant());
    }

    public function test_a_company_admin_does_not_count(): void
    {
        // Admins are created out-of-band, already approved, and never pass
        // through the sign-up gate at all.
        $this->assertFalse(User::factory()->companyAdmin()->create([
            'company_id' => $this->genesenn->id,
            'email_verified_at' => null,
        ])->isUnconfirmedApplicant());
    }

    // ── The flag reaches the screen ──────────────────────────────────

    public function test_the_roster_says_which_rows_are_unfinished_sign_ups(): void
    {
        $applicant = $this->applicant();
        $working = $this->applicant([
            'agent_approval_status' => AgentApprovalStatus::Approved,
            'email_verified_at' => now(),
        ]);

        $rows = collect($this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users?include_inactive=1&with_permissions=1')
            ->assertOk()
            ->json('data'))
            ->keyBy('id');

        $this->assertTrue($rows[$applicant->id]['is_unconfirmed_applicant']);
        $this->assertFalse($rows[$working->id]['is_unconfirmed_applicant']);
        // The button the screen draws is gated on the Policy's own answer,
        // not on the flag alone.
        $this->assertTrue($rows[$applicant->id]['permissions']['deactivate']);
    }

    public function test_the_roster_gets_the_two_decision_permissions_separately(): void
    {
        /*
         * 2026-09-08 — the roster decides on registrations now, and the two
         * verbs are not one permission: approve is also open to a team leader
         * over their own recruits (ADR-025 §7), reject is admin-only. One
         * combined flag would put a ไม่อนุมัติ button in front of every leader
         * who can approve — and a rejection writes a permanent negative record
         * that the registrant is shown by name at the login screen.
         */
        $applicant = $this->applicant();

        $permissions = collect($this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users?include_inactive=1&with_permissions=1')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $applicant->id)['permissions'];

        $this->assertArrayHasKey('approve_registration', $permissions);
        $this->assertArrayHasKey('reject_registration', $permissions);
        $this->assertTrue($permissions['approve_registration']);
        $this->assertTrue($permissions['reject_registration']);
    }

    public function test_an_agent_is_told_no_on_both_decisions(): void
    {
        // The roster is reachable by nobody else, but the resource must not be
        // the thing that assumes so.
        $applicant = $this->applicant();

        $rows = $this->actingAs(User::factory()->agent()->create(['company_id' => $this->genesenn->id]))
            ->getJson('/api/v1/users?with_permissions=1');

        // viewAny refuses an agent outright, so there is no row to answer for
        // — which is the strongest form of "no".
        $rows->assertForbidden();
        $this->assertNotNull($applicant->id);
    }

    // ── Removing one, and putting it back ────────────────────────────

    public function test_a_company_admin_removes_an_applicant_from_their_own_company(): void
    {
        $applicant = $this->applicant();
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->genesenn->id]);

        $this->actingAs($actor)
            ->deleteJson("/api/v1/users/{$applicant->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $applicant->id]);
    }

    public function test_a_super_admin_removes_an_applicant_from_any_company(): void
    {
        $applicant = $this->applicant();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->deleteJson("/api/v1/users/{$applicant->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $applicant->id]);
    }

    public function test_a_company_admin_cannot_reach_another_companys_applicant(): void
    {
        // BR-6. The roster never shows them the row, and the endpoint refuses
        // it independently.
        $other = Company::factory()->create(['name' => 'Thai Life']);
        $applicant = User::factory()->agent()->create([
            'company_id' => $other->id,
            'agent_approval_status' => AgentApprovalStatus::Pending,
        ]);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->genesenn->id]))
            ->deleteJson("/api/v1/users/{$applicant->id}")
            ->assertNotFound();

        $this->assertNotSoftDeleted('users', ['id' => $applicant->id]);
    }

    public function test_an_agent_cannot_remove_anybody(): void
    {
        $applicant = $this->applicant();

        $this->actingAs(User::factory()->agent()->create(['company_id' => $this->genesenn->id]))
            ->deleteJson("/api/v1/users/{$applicant->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('users', ['id' => $applicant->id]);
    }

    public function test_removal_is_reversible(): void
    {
        /*
         * What makes this safe to offer on a list row at all. The human asked
         * for a SOFT delete by name, and the screen has to keep that promise:
         * the row is still there, still restorable, with every reference to it
         * intact (a hard delete is not even possible — some two dozen foreign
         * keys restrict it).
         */
        $applicant = $this->applicant();
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)->deleteJson("/api/v1/users/{$applicant->id}")->assertNoContent();
        $this->actingAs($actor)->postJson("/api/v1/users/{$applicant->id}/restore")->assertOk();

        $this->assertNotSoftDeleted('users', ['id' => $applicant->id]);
    }

    public function test_the_trail_says_which_of_the_two_deletions_it_was(): void
    {
        /*
         * Removing an unfinished sign-up and switching off a trading agent
         * write the same column, so the audit action is the only place the
         * difference survives — and "why did this account go away" is most of
         * what the trail is for.
         */
        $applicant = $this->applicant();
        $working = $this->applicant([
            'agent_approval_status' => AgentApprovalStatus::Approved,
            'email_verified_at' => now(),
        ]);
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)->deleteJson("/api/v1/users/{$applicant->id}")->assertNoContent();
        $this->actingAs($actor)->deleteJson("/api/v1/users/{$working->id}")->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.applicant_removed',
            'auditable_id' => $applicant->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.deactivated',
            'auditable_id' => $working->id,
        ]);
    }

    public function test_the_action_is_decided_by_the_row_not_by_the_caller(): void
    {
        // No request field names the audit action, so a client cannot label a
        // trading agent's deactivation as a tidy-up of a junk sign-up.
        $working = $this->applicant([
            'agent_approval_status' => AgentApprovalStatus::Approved,
            'email_verified_at' => now(),
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->deleteJson("/api/v1/users/{$working->id}", ['action' => 'user.applicant_removed'])
            ->assertNoContent();

        $this->assertSame(0, AuditLog::where('action', 'user.applicant_removed')
            ->where('auditable_id', $working->id)
            ->count());
    }
}
