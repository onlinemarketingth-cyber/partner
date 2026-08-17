<?php

namespace Tests\Feature\Registration;

use App\Enums\AgentApprovalStatus;
use App\Enums\ApprovalSource;
use App\Models\AgentInviteLink;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Registration\LeaderRecruitScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-115 / ADR-025 §7 — the leader-scoped approval carve-out.
 *
 * ADR-025 §7 states the scope exactly and says "anything else → 403". This
 * file is that sentence, executable: one happy path, then one test per way
 * of being outside the scope. AgentApprovalTest (TASK-020) still covers the
 * admin path and is untouched.
 */
class LeaderApprovalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The canonical in-scope setup: a leader, one of THEIR links, and a
     * pending recruit who came in through it and reports to them.
     *
     * @return array{0: Company, 1: User, 2: AgentInviteLink, 3: User}
     */
    private function leaderWithRecruit(): array
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);

        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'label' => 'แคมเปญกรกฎาคม',
        ]);

        $recruit = User::factory()->agent()->pendingApproval()->create([
            'company_id' => $company->id,
            'manager_id' => $leader->id,
            'recruited_via_agent_link_id' => $link->id,
            'email_verified_at' => now(),
        ]);

        return [$company, $leader, $link, $recruit];
    }

    // ── The happy path ────────────────────────────────────────────────────

    public function test_a_leader_can_approve_their_own_recruit(): void
    {
        [, $leader, , $recruit] = $this->leaderWithRecruit();

        $this->actingAs($leader)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.agent_approval_status', 'approved');

        $fresh = $recruit->fresh();
        $this->assertSame(AgentApprovalStatus::Approved, $fresh->agent_approval_status);
        // ADR-025 §7's mitigation: the row itself says a LEADER did this, and
        // names them. TASK-117's queue reads exactly these.
        $this->assertSame(ApprovalSource::TeamLeader, $fresh->approval_source);
        $this->assertSame($leader->id, $fresh->approved_by_user_id);
        $this->assertNotNull($fresh->approved_at);
    }

    /** Section 6 audit log — actor, subject, before → after. */
    public function test_a_leader_approval_writes_an_audit_row_naming_the_leader(): void
    {
        [$company, $leader, , $recruit] = $this->leaderWithRecruit();

        $this->actingAs($leader)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")
            ->assertOk();

        $audit = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $recruit->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        // A DISTINCT action string, so "who did a leader let in" is one WHERE.
        $this->assertSame('agent_approval.approved_by_leader', $audit->action);
        $this->assertSame($leader->id, $audit->actor_user_id);
        $this->assertSame($company->id, $audit->company_id);
        $this->assertSame('pending', $audit->old_values['agent_approval_status']);
        $this->assertSame('approved', $audit->new_values['agent_approval_status']);
        $this->assertSame('team_leader', $audit->new_values['approval_source']);
    }

    // ── Everything outside the scope → 403 ────────────────────────────────

    public function test_a_leader_cannot_approve_another_leaders_recruit(): void
    {
        [$company, , , ] = $this->leaderWithRecruit();

        // A second leader in the SAME company, with their own link and recruit.
        $otherLeader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $otherLink = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $otherLeader->id,
        ]);
        $otherRecruit = User::factory()->agent()->pendingApproval()->create([
            'company_id' => $company->id,
            'manager_id' => $otherLeader->id,
            'recruited_via_agent_link_id' => $otherLink->id,
        ]);

        $intruder = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);

        $this->actingAs($intruder)
            ->putJson("/api/v1/agent-approvals/{$otherRecruit->id}/approve")
            ->assertForbidden();

        $this->assertSame(AgentApprovalStatus::Pending, $otherRecruit->fresh()->agent_approval_status);
    }

    /**
     * ADR-025 §7's fourth condition, isolated: reporting to the leader is NOT
     * enough — the recruit must also have arrived through one of the
     * leader's own links. Without this the carve-out would silently widen to
     * "any agent an Admin ever placed under me".
     */
    public function test_a_leader_cannot_approve_a_direct_report_who_did_not_come_through_their_link(): void
    {
        [$company, $leader, , ] = $this->leaderWithRecruit();

        $placedByAdmin = User::factory()->agent()->pendingApproval()->create([
            'company_id' => $company->id,
            'manager_id' => $leader->id,
            'recruited_via_agent_link_id' => null,
        ]);

        $this->actingAs($leader)
            ->putJson("/api/v1/agent-approvals/{$placedByAdmin->id}/approve")
            ->assertForbidden();
    }

    /**
     * And the mirror image: their own link, but an Admin has since re-pointed
     * manager_id elsewhere. "Their own tree" is the live hierarchy.
     */
    public function test_a_leader_cannot_approve_their_own_links_recruit_once_the_manager_has_changed(): void
    {
        [$company, $leader, $link, $recruit] = $this->leaderWithRecruit();

        $newManager = User::factory()->agent()->create(['company_id' => $company->id]);
        $recruit->forceFill(['manager_id' => $newManager->id])->save();

        $this->assertSame($link->id, $recruit->fresh()->recruited_via_agent_link_id);

        $this->actingAs($leader)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")
            ->assertForbidden();
    }

    public function test_a_leader_cannot_approve_an_already_approved_user(): void
    {
        [, $leader, , $recruit] = $this->leaderWithRecruit();

        $recruit->forceFill(['agent_approval_status' => AgentApprovalStatus::Approved])->save();

        // 403, not the admin path's 422: for a leader, "not pending" is
        // outside the scope entirely, so the Policy answers before the
        // Service's assertPending() is ever reached.
        $this->actingAs($leader)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")
            ->assertForbidden();
    }

    /**
     * BR-6 — cross-tenant, blocked by TenantScope's route-model binding.
     *
     * SCOPE WARNING (TASK-119 / QA finding D2): this test proves the ROUTE is
     * closed, and nothing more. The 404 below is produced by route-model
     * binding, which runs BEFORE the Policy, which runs before
     * LeaderRecruitScope. Delete the `company_id` guard inside
     * LeaderRecruitScope::mayApprove() and this test still passes. It is
     * therefore NOT coverage for that guard — the direct assertions further
     * down are. Both are kept: this one pins the HTTP behaviour (404, not
     * 403), those pin the predicate itself.
     */
    public function test_a_leader_cannot_approve_a_recruit_in_another_company(): void
    {
        [, $leader, , ] = $this->leaderWithRecruit();

        $otherCompany = Company::factory()->create();
        $otherLeader = User::factory()->agent()->teamLeader()->create(['company_id' => $otherCompany->id]);
        $otherLink = AgentInviteLink::factory()->create([
            'company_id' => $otherCompany->id,
            'agent_id' => $otherLeader->id,
        ]);
        $foreignRecruit = User::factory()->agent()->pendingApproval()->create([
            'company_id' => $otherCompany->id,
            'manager_id' => $otherLeader->id,
            'recruited_via_agent_link_id' => $otherLink->id,
        ]);

        // 404 rather than 403 — TenantScope narrows the {user} route-model
        // binding, so the row is not merely forbidden, it is invisible. Same
        // behaviour AgentApprovalTest already pins for the admin path.
        $this->actingAs($leader)
            ->putJson("/api/v1/agent-approvals/{$foreignRecruit->id}/approve")
            ->assertNotFound();

        $this->assertSame(AgentApprovalStatus::Pending, $foreignRecruit->fresh()->agent_approval_status);
    }

    // ── The guard itself, called directly (TASK-119 / QA finding D2) ──────
    //
    // Every test above reaches LeaderRecruitScope through HTTP, where three
    // earlier layers can answer first: route-model binding (TenantScope),
    // UserPolicy, the route definition. That is the right way to pin ENDPOINT
    // behaviour and a useless way to pin THIS CLASS — the cross-tenant case
    // 404s at the binding and never executes a single line of mayApprove().
    //
    // LeaderRecruitScope's own docblock justifies its hand-written company_id
    // predicate precisely for callers with NO route binding ("a queue job, a
    // console command", where auth()->user() is null and TenantScope is a
    // no-op). That path had zero coverage. These four tests are it: the class
    // is resolved from the container and called as a plain method, with NO
    // acting user — the queue-job context, reproduced.
    //
    // Each negative deliberately FORGES every other condition into its passing
    // state (manager_id, recruited_via_agent_link_id pointing at a real link
    // the actor owns, pending status, the flag), so that the guard under test
    // is the only thing left standing between the actor and a `true`. Delete
    // that one line and the assertion flips — which is the property the HTTP
    // test above does not have. Some of these rows are ones the guarded write
    // paths would refuse to produce (assertValidManager() forbids both a
    // cross-company manager_id and manager_id = self); they are written with
    // forceFill/factories on purpose, because "another file prevents this"
    // is not the same as "this file rejects it", and a corrupt row, a data
    // migration or a future caller can produce what a Service refuses to.
    //
    // The positive control is not optional: without it, all three negatives
    // could be passing because the fixture is broken rather than because the
    // guard works.

    private function scope(): LeaderRecruitScope
    {
        return app(LeaderRecruitScope::class);
    }

    /**
     * POSITIVE CONTROL. If this ever fails, the three negatives below stop
     * meaning anything — read this one first.
     */
    public function test_the_scope_itself_allows_a_genuinely_valid_leader_and_recruit_pair(): void
    {
        [, $leader, , $recruit] = $this->leaderWithRecruit();

        $this->assertTrue($this->scope()->mayApprove($leader, $recruit));
    }

    /**
     * BR-6, on the predicate rather than on the route. The target sits in
     * another company but is otherwise a perfect match for this leader —
     * their direct report, attributed to their own link, pending.
     *
     * This is the test that fails if LeaderRecruitScope's company_id check is
     * deleted. The HTTP test above is not.
     */
    public function test_the_scope_itself_refuses_a_cross_tenant_target_with_every_other_condition_forged(): void
    {
        [$company, $leader, $link, ] = $this->leaderWithRecruit();

        $otherCompany = Company::factory()->create();

        $foreignRecruit = User::factory()->agent()->pendingApproval()->create([
            'company_id' => $otherCompany->id,
            // Forged: assertValidManager() would reject a cross-company
            // manager_id, but nothing in the DB schema prevents the row.
            'manager_id' => $leader->id,
            'recruited_via_agent_link_id' => $link->id,
        ]);

        // Everything except the tenant matches — asserted, not assumed, so a
        // future factory change cannot quietly turn this into a test that
        // passes for the wrong reason.
        $this->assertSame($leader->id, $foreignRecruit->manager_id);
        $this->assertSame($link->id, $foreignRecruit->recruited_via_agent_link_id);
        $this->assertSame(AgentApprovalStatus::Pending, $foreignRecruit->agent_approval_status);
        $this->assertNotSame($company->id, $foreignRecruit->company_id);

        $this->assertFalse($this->scope()->mayApprove($leader, $foreignRecruit));
    }

    /**
     * ADR-025 §7 — a leader is never their own approver. The class comment
     * says this "is impossible anyway (assertValidManager forbids manager_id
     * = self)"; this test is what makes that claim independent of the other
     * file, by writing exactly the row assertValidManager() forbids.
     */
    public function test_the_scope_itself_refuses_the_actor_as_their_own_target(): void
    {
        [, $leader, $link, ] = $this->leaderWithRecruit();

        $leader->forceFill([
            'manager_id' => $leader->id,
            'recruited_via_agent_link_id' => $link->id,
            'agent_approval_status' => AgentApprovalStatus::Pending,
        ])->save();

        $leader->refresh();

        // Same company as itself, its own manager, its own link, pending, and
        // flagged — every condition but the self-check is satisfied.
        $this->assertFalse($this->scope()->mayApprove($leader, $leader));
    }

    /**
     * The admin early-return, isolated. The actor deliberately carries
     * is_team_leader = true — without it the FIRST check would answer and this
     * would prove nothing about the branch under test.
     *
     * False here does not mean an admin cannot approve: it means the LEADER
     * branch declines to claim the decision, so it cannot be recorded as
     * approval_source = team_leader. The admin's real power is
     * UserPolicy::update(), covered by the HTTP tests further down.
     */
    public function test_the_scope_itself_refuses_an_admin_actor_even_when_they_carry_the_leader_flag(): void
    {
        $company = Company::factory()->create();

        $admin = User::factory()->companyAdmin()->teamLeader()->create([
            'company_id' => $company->id,
        ]);

        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $admin->id,
        ]);

        $recruit = User::factory()->agent()->pendingApproval()->create([
            'company_id' => $company->id,
            'manager_id' => $admin->id,
            'recruited_via_agent_link_id' => $link->id,
        ]);

        $this->assertTrue((bool) $admin->is_team_leader);
        $this->assertTrue($admin->isCompanyAdmin());

        $this->assertFalse($this->scope()->mayApprove($admin, $recruit));
    }

    /** ADR-025 §2 — revoking the flag stops approving, immediately. */
    public function test_an_agent_without_the_team_leader_flag_cannot_approve_even_their_own_recruit(): void
    {
        [, $leader, , $recruit] = $this->leaderWithRecruit();

        $leader->forceFill(['is_team_leader' => false])->save();

        $this->actingAs($leader->fresh())
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")
            ->assertForbidden();
    }

    /**
     * TASK-115: "a leader may not change role, company_id, manager_id, or
     * reject-then-reassign."
     *
     * The approval endpoint accepts NO body at all, so the only way to try is
     * to send those keys and watch them be ignored. This test exists to catch
     * a future edit that adds a Form Request to this route and accidentally
     * makes them writable.
     */
    public function test_a_leader_cannot_change_role_company_or_manager_through_the_approval_path(): void
    {
        [$company, $leader, , $recruit] = $this->leaderWithRecruit();

        $otherCompany = Company::factory()->create();

        $this->actingAs($leader)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/approve", [
                'role' => 'company_admin',
                'company_id' => $otherCompany->id,
                'manager_id' => null,
                'is_team_leader' => true,
                // And the attribution columns themselves — a leader must not
                // be able to forge "an admin approved this".
                'approval_source' => ApprovalSource::Admin->value,
                'approved_by_user_id' => 999,
            ])
            ->assertOk();

        $fresh = $recruit->fresh();
        $this->assertTrue($fresh->isAgent());
        $this->assertSame($company->id, $fresh->company_id);
        $this->assertSame($leader->id, $fresh->manager_id);
        $this->assertFalse((bool) $fresh->is_team_leader);
        $this->assertSame(ApprovalSource::TeamLeader, $fresh->approval_source);
        $this->assertSame($leader->id, $fresh->approved_by_user_id);
    }

    /** Approve only — never reject (see AgentApprovalService's class docblock). */
    public function test_a_leader_cannot_reject_their_own_recruit(): void
    {
        [, $leader, , $recruit] = $this->leaderWithRecruit();

        $this->actingAs($leader)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/reject", ['reason' => 'ไม่เอา'])
            ->assertForbidden();

        $this->assertSame(AgentApprovalStatus::Pending, $recruit->fresh()->agent_approval_status);
    }

    public function test_a_leader_cannot_revoke_an_approval(): void
    {
        [$company, $leader, , $recruit] = $this->leaderWithRecruit();

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")->assertOk();

        $this->actingAs($leader)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/revoke")
            ->assertForbidden();
    }

    // ── The Company Admin keeps full power ────────────────────────────────

    public function test_a_company_admin_can_still_approve_and_is_recorded_as_the_admin_source(): void
    {
        [$company, , , $recruit] = $this->leaderWithRecruit();

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")
            ->assertOk();

        $fresh = $recruit->fresh();
        $this->assertSame(ApprovalSource::Admin, $fresh->approval_source);
        $this->assertSame($admin->id, $fresh->approved_by_user_id);
        $this->assertSame(
            'agent_approval.approved',
            AuditLog::where('auditable_id', $recruit->id)->latest('id')->first()->action,
        );
    }

    /**
     * ADR-025 §7: "Company Admins keep the full approval queue and can
     * reverse anything a leader did." Before TASK-115 there was no way to
     * reverse anything at all.
     */
    public function test_a_company_admin_can_reverse_a_leaders_approval(): void
    {
        [$company, $leader, , $recruit] = $this->leaderWithRecruit();

        $this->actingAs($leader)->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")->assertOk();

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/revoke", ['reason' => 'ยังไม่ผ่านการตรวจสอบจากบริษัท'])
            ->assertOk()
            ->assertJsonPath('data.agent_approval_status', 'rejected');

        $fresh = $recruit->fresh();
        $this->assertSame(AgentApprovalStatus::Rejected, $fresh->agent_approval_status);
        $this->assertSame('ยังไม่ผ่านการตรวจสอบจากบริษัท', $fresh->approval_rejection_reason);
        // The stale approval attribution is cleared...
        $this->assertNull($fresh->approved_by_user_id);
        $this->assertNull($fresh->approval_source);

        // ...but the audit row records WHOSE decision was overturned, which is
        // the only durable trace that a leader had let this person in.
        $audit = AuditLog::where('auditable_id', $recruit->id)->latest('id')->first();
        $this->assertSame('agent_approval.revoked', $audit->action);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame($leader->id, $audit->old_values['approved_by_user_id']);
        $this->assertSame('team_leader', $audit->old_values['approval_source']);
    }

    public function test_revoking_a_registrant_who_is_not_approved_is_rejected(): void
    {
        [$company, , , $recruit] = $this->leaderWithRecruit();

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/agent-approvals/{$recruit->id}/revoke")
            ->assertUnprocessable();
    }

    /** TASK-117 needs to SEE leader-approved rows; the default view is unchanged. */
    public function test_the_admin_queue_can_list_approved_registrants_and_names_the_approver(): void
    {
        [$company, $leader, , $recruit] = $this->leaderWithRecruit();

        $this->actingAs($leader)->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")->assertOk();

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // Default (no ?status=) still shows only Pending — TASK-020 behaviour
        // must not change.
        $default = $this->actingAs($admin)->getJson('/api/v1/agent-approvals')->assertOk();
        $this->assertNotContains($recruit->id, collect($default->json('data'))->pluck('id')->all());

        $approved = $this->actingAs($admin)->getJson('/api/v1/agent-approvals?status=approved')->assertOk();
        $row = collect($approved->json('data'))->firstWhere('id', $recruit->id);

        $this->assertNotNull($row);
        $this->assertSame('team_leader', $row['approval_source']);
        $this->assertSame($leader->id, $row['approved_by']['id']);
        $this->assertSame($leader->name, $row['approved_by']['name']);
    }

    // ── The leader's own pending-recruit list (TASK-116 needs it) ─────────

    public function test_the_pending_recruit_list_shows_only_the_leaders_own_recruits(): void
    {
        [$company, $leader, $link, $recruit] = $this->leaderWithRecruit();

        // Another leader's recruit — same company, must not appear.
        $otherLeader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $otherLink = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $otherLeader->id,
        ]);
        $otherRecruit = User::factory()->agent()->pendingApproval()->create([
            'company_id' => $company->id,
            'manager_id' => $otherLeader->id,
            'recruited_via_agent_link_id' => $otherLink->id,
        ]);

        // This leader's own direct report who did NOT come via their link.
        $placedByAdmin = User::factory()->agent()->pendingApproval()->create([
            'company_id' => $company->id,
            'manager_id' => $leader->id,
            'recruited_via_agent_link_id' => null,
        ]);

        // This leader's own recruit, already decided — no longer actionable.
        $alreadyApproved = User::factory()->agent()->create([
            'company_id' => $company->id,
            'manager_id' => $leader->id,
            'recruited_via_agent_link_id' => $link->id,
            'agent_approval_status' => AgentApprovalStatus::Approved,
        ]);

        $ids = collect(
            $this->actingAs($leader)->getJson('/api/v1/agent-approvals/my-recruits')->assertOk()->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($recruit->id));
        $this->assertFalse($ids->contains($otherRecruit->id));
        $this->assertFalse($ids->contains($placedByAdmin->id));
        $this->assertFalse($ids->contains($alreadyApproved->id));
    }

    /**
     * PDPA / ADR-024 §3 — a leader sees no more about a recruit than they
     * already see on /me/team. If someone swaps this endpoint to
     * UserResource "for consistency", this fails.
     */
    public function test_the_pending_recruit_list_exposes_no_contact_details(): void
    {
        [, $leader, , $recruit] = $this->leaderWithRecruit();

        $row = collect(
            $this->actingAs($leader)->getJson('/api/v1/agent-approvals/my-recruits')->assertOk()->json('data')
        )->firstWhere('id', $recruit->id);

        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('phone', $row);
        $this->assertArrayNotHasKey('national_id', $row);
        $this->assertArrayNotHasKey('national_id_masked', $row);
        $this->assertArrayNotHasKey('bank_account_number', $row);
        // What it DOES carry: enough to identify the person and the campaign.
        $this->assertSame($recruit->name, $row['name']);
        $this->assertSame('แคมเปญกรกฎาคม', $row['invite_link']['label']);
        $this->assertTrue($row['email_verified']);
    }

    public function test_a_non_leader_agent_cannot_read_the_pending_recruit_list(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/agent-approvals/my-recruits')
            ->assertForbidden();
    }

    /** A plain agent has no business in the approval queue at all (TASK-020). */
    public function test_a_plain_agent_still_cannot_read_the_admin_approval_queue(): void
    {
        [, $leader, , ] = $this->leaderWithRecruit();

        $this->actingAs($leader)->getJson('/api/v1/agent-approvals')->assertForbidden();
    }
}
