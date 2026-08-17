<?php

namespace Tests\Feature\Registration;

use App\Models\AgentInviteLink;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-112 / ADR-025 §3 — the `agent_invite_links` table, its model, and
 * AgentInviteLink::isUsable().
 *
 * Named ...SchemaTest (mirroring RegistrationSchemaTest) because it tests
 * the data layer only: there is no controller, route or minting Service
 * yet — those are TASK-113/114, and their own tests will exercise the
 * HTTP surface. Everything here goes through the model and the Policy
 * directly, which is why the Policy assertions use $user->can() rather
 * than an HTTP status code.
 */
class AgentInviteLinkSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User} */
    private function companyWithLeader(): array
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);

        return [$company, $leader];
    }

    // ── isUsable(): every combination (TASK-112 item 6) ────────────────

    /**
     * Acceptance criterion: "a link with both limits null is usable
     * indefinitely." NULL means UNLIMITED on both axes (ADR-025 §3), which
     * is a configuration the human explicitly asked for — not a missing
     * value waiting for a default.
     */
    public function test_a_fresh_link_with_both_limits_null_is_usable(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $this->assertNull($link->expires_at);
        $this->assertNull($link->max_uses);
        $this->assertTrue($link->isUsable());

        // ...and still usable far in the future — "no expiry" must not
        // silently mean "expires at some implicit horizon".
        $this->travel(10)->years();
        $this->assertTrue($link->fresh()->isUsable());
    }

    public function test_a_revoked_link_is_not_usable(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->revoked()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $this->assertFalse($link->isUsable());
    }

    public function test_a_revoked_link_is_not_usable_even_when_both_limits_are_generous(): void
    {
        // Guards the AND-not-OR shape of isUsable(): a revoke must win over
        // a wide-open expiry and quota.
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'expires_at' => now()->addYear(),
            'max_uses' => 100,
            'used_count' => 0,
            'revoked_at' => now(),
        ]);

        $this->assertFalse($link->isUsable());
    }

    public function test_an_expired_link_is_not_usable(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->expired()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $this->assertFalse($link->isUsable());
    }

    public function test_a_link_expiring_in_the_future_is_still_usable(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'expires_at' => now()->addDay(),
        ]);

        $this->assertTrue($link->isUsable());
    }

    public function test_a_link_becomes_unusable_the_moment_it_expires(): void
    {
        // isFuture() is exclusive of "now", so the boundary is closed at
        // the expiry instant rather than one tick later.
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'expires_at' => now()->addHour(),
        ]);
        $this->assertTrue($link->isUsable());

        $this->travel(61)->minutes();
        $this->assertFalse($link->fresh()->isUsable());
    }

    public function test_a_link_whose_quota_is_reached_is_not_usable(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->quotaReached()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $this->assertSame(1, $link->max_uses);
        $this->assertSame(1, $link->used_count);
        $this->assertFalse($link->isUsable());
    }

    public function test_a_link_whose_quota_is_somehow_exceeded_is_not_usable(): void
    {
        // Defensive: used_count > max_uses should be impossible once
        // TASK-114's row lock is in place (ADR-025 §4), but if it ever
        // happens the link must fail CLOSED, not wrap back to usable.
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'max_uses' => 2,
            'used_count' => 5,
        ]);

        $this->assertFalse($link->isUsable());
    }

    public function test_a_link_whose_quota_is_not_yet_reached_is_usable(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'max_uses' => 5,
            'used_count' => 4,
        ]);

        $this->assertTrue($link->isUsable());
    }

    public function test_a_quota_is_unlimited_when_max_uses_is_null_no_matter_how_many_recruits_joined(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'max_uses' => null,
            'used_count' => 9_999,
        ]);

        $this->assertTrue($link->isUsable());
    }

    public function test_both_limits_set_and_both_satisfied_is_usable(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'expires_at' => now()->addWeek(),
            'max_uses' => 10,
            'used_count' => 3,
        ]);

        $this->assertTrue($link->isUsable());
    }

    public function test_both_limits_set_and_only_one_violated_is_not_usable(): void
    {
        // Each limit must be able to fail the link ON ITS OWN — the classic
        // bug in a three-condition check is an accidental OR.
        [$company, $leader] = $this->companyWithLeader();

        $expiredButUnderQuota = AgentInviteLink::factory()->create([
            'company_id' => $company->id, 'agent_id' => $leader->id,
            'expires_at' => now()->subDay(), 'max_uses' => 10, 'used_count' => 0,
        ]);
        $this->assertFalse($expiredButUnderQuota->isUsable());

        $inDateButQuotaExhausted = AgentInviteLink::factory()->create([
            'company_id' => $company->id, 'agent_id' => $leader->id,
            'expires_at' => now()->addWeek(), 'max_uses' => 10, 'used_count' => 10,
        ]);
        $this->assertFalse($inDateButQuotaExhausted->isUsable());
    }

    // ── Model shape / relations ────────────────────────────────────────

    public function test_relations_resolve_in_both_directions(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);
        $recruit = User::factory()->agent()->create([
            'company_id' => $company->id,
            'recruited_via_agent_link_id' => $link->id,
        ]);

        $this->assertSame($company->id, $link->company->id);
        $this->assertSame($leader->id, $link->agent->id);
        $this->assertTrue($link->recruits->contains($recruit));
        $this->assertTrue($leader->agentInviteLinks->contains($link));
        $this->assertSame($link->id, $recruit->recruitedViaAgentLink->id);
    }

    public function test_attribution_survives_a_soft_revoke(): void
    {
        // ADR-025 §3/TASK-113 — revoke is soft precisely so
        // users.recruited_via_agent_link_id is never nulled out from under
        // an existing recruit.
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);
        $recruit = User::factory()->agent()->create([
            'company_id' => $company->id,
            'recruited_via_agent_link_id' => $link->id,
        ]);

        $link->update(['revoked_at' => now()]);

        $this->assertFalse($link->fresh()->isUsable());
        $this->assertSame($link->id, $recruit->fresh()->recruited_via_agent_link_id);
    }

    // ── BR-6: TenantScope on the model ─────────────────────────────────

    public function test_tenant_scope_hides_another_companys_links(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);
        $linkA = AgentInviteLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $leaderA->id]);

        $this->actingAs($agentB);

        $this->assertNull(AgentInviteLink::find($linkA->id));
        $this->assertSame(0, AgentInviteLink::count());
    }

    public function test_tenant_scope_shows_own_company_links_and_super_admin_sees_across_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $companyA->id]);
        $leaderB = User::factory()->agent()->teamLeader()->create(['company_id' => $companyB->id]);
        AgentInviteLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $leaderA->id]);
        AgentInviteLink::factory()->create(['company_id' => $companyB->id, 'agent_id' => $leaderB->id]);

        $this->actingAs($leaderA);
        $this->assertSame(1, AgentInviteLink::count());

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->assertSame(2, AgentInviteLink::count());
    }

    // ── AgentInviteLinkPolicy ──────────────────────────────────────────

    public function test_an_agent_may_view_and_delete_only_their_own_link(): void
    {
        $company = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $leaderB = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $linkA = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leaderA->id]);

        $this->assertTrue($leaderA->can('view', $linkA));
        $this->assertTrue($leaderA->can('delete', $linkA));

        // Same company, different owner — still denied (Section 5 rule 4:
        // an Agent sees only agent_id = self).
        $this->assertFalse($leaderB->can('view', $linkA));
        $this->assertFalse($leaderB->can('delete', $linkA));
    }

    public function test_company_admin_may_view_and_delete_any_link_in_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $this->assertTrue($admin->can('view', $link));
        $this->assertTrue($admin->can('delete', $link));
    }

    public function test_company_admin_may_not_touch_another_companys_link(): void
    {
        // BR-6 — the Policy layer of the cross-tenant guard. TenantScope
        // would normally hide the row before the Policy ever runs; this
        // asserts the Policy ALSO says no, so an unscoped query (e.g. a
        // public token lookup in TASK-114) can't become an authorization
        // hole on its own.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $companyA->id]);
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $linkA = AgentInviteLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $leaderA->id]);

        $this->assertFalse($adminB->can('view', $linkA));
        $this->assertFalse($adminB->can('delete', $linkA));
    }

    public function test_an_agent_may_not_touch_another_companys_link(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $companyA->id]);
        $leaderB = User::factory()->agent()->teamLeader()->create(['company_id' => $companyB->id]);
        $linkA = AgentInviteLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $leaderA->id]);

        $this->assertFalse($leaderB->can('view', $linkA));
        $this->assertFalse($leaderB->can('delete', $linkA));
    }

    public function test_super_admin_may_view_any_companys_link(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertTrue($superAdmin->can('view', $link));
        $this->assertTrue($superAdmin->can('delete', $link));
    }
}
