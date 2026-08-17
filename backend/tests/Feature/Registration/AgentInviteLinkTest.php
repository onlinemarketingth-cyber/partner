<?php

namespace Tests\Feature\Registration;

use App\Models\AgentInviteLink;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-113 / ADR-025 §3 — the HTTP surface for minting, listing and
 * soft-revoking a team leader's recruit links.
 *
 * Complements AgentInviteLinkSchemaTest (TASK-112), which covers the model
 * and isUsable() at the data layer; everything here goes through the real
 * routes so the Policy, the Form Request and TenantScope are all exercised
 * the way a client would exercise them.
 *
 * Out of scope by design (TASK-114): the public resolve-ref-token
 * endpoint, registration through a link, and the atomic used_count
 * increment.
 */
class AgentInviteLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User} */
    private function companyWithLeader(): array
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);

        return [$company, $leader];
    }

    // ── Minting: the is_team_leader gate (ADR-025 §1) ──────────────────

    public function test_a_designated_team_leader_can_mint_a_recruit_link(): void
    {
        [, $leader] = $this->companyWithLeader();

        $response = $this->actingAs($leader)
            ->postJson('/api/v1/agent-invite-links', ['label' => 'Open House ตุลาคม'])
            ->assertCreated();

        $response->assertJsonPath('data.agent_id', $leader->id)
            ->assertJsonPath('data.company_id', $leader->company_id)
            ->assertJsonPath('data.label', 'Open House ตุลาคม')
            ->assertJsonPath('data.used_count', 0)
            // Both limits omitted => unlimited (ADR-025 §3), so the fresh
            // link must be usable.
            ->assertJsonPath('data.max_uses', null)
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.is_usable', true);

        // TASK-116 builds its QR/copy affordance on public_url — assert the
        // exact shape, not just that the key exists.
        $token = $response->json('data.token');
        $this->assertSame(64, strlen((string) $token));
        $frontendUrl = rtrim((string) config('services.agent_portal.frontend_url'), '/');
        $this->assertSame("{$frontendUrl}/register?ref={$token}", $response->json('data.public_url'));
    }

    /**
     * Acceptance criterion: "non-leader gets a validation error."
     * 422 rather than 403 on purpose — the actor is authorised to attempt
     * this, they simply lack a business flag an Admin can grant.
     */
    public function test_an_agent_without_the_team_leader_flag_cannot_mint_a_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->assertFalse($agent->is_team_leader);

        $this->actingAs($agent)
            ->postJson('/api/v1/agent-invite-links', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_team_leader');

        $this->assertSame(0, AgentInviteLink::withoutGlobalScopes()->count());
    }

    /**
     * ADR-025 §2 — revoking the flag stops FUTURE recruiting. (That an
     * already-minted link keeps working until revoked/expired is TASK-114's
     * problem, flagged as undecided in AgentInviteLink::isUsable()'s
     * docblock; this test only pins the minting half.)
     */
    public function test_a_leader_who_loses_the_flag_can_no_longer_mint(): void
    {
        [, $leader] = $this->companyWithLeader();

        $this->actingAs($leader)->postJson('/api/v1/agent-invite-links', [])->assertCreated();

        $leader->update(['is_team_leader' => false]);

        $this->actingAs($leader->fresh())
            ->postJson('/api/v1/agent-invite-links', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_team_leader');
    }

    // ── Deliberately NOT idempotent (TASK-113 spec) ────────────────────

    /**
     * Acceptance criterion: "a leader can hold several links."
     *
     * This is the behavioural opposite of
     * ProductShareLinkService::create(), which reuses an existing link
     * rather than minting a second — if someone "fixes" this Service to
     * match that precedent, THIS test is what fails.
     */
    public function test_a_leader_may_hold_several_live_links_with_different_limits_at_once(): void
    {
        [, $leader] = $this->companyWithLeader();

        $first = $this->actingAs($leader)->postJson('/api/v1/agent-invite-links', [
            'label' => 'Open House',
            'max_uses' => 20,
        ])->assertCreated();

        $second = $this->actingAs($leader)->postJson('/api/v1/agent-invite-links', [
            'label' => 'เพื่อนแนะนำเพื่อน',
        ])->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertNotSame($first->json('data.token'), $second->json('data.token'));

        // Both alive at the same time, with their own independent limits.
        $this->assertTrue($first->json('data.is_usable'));
        $this->assertTrue($second->json('data.is_usable'));
        $this->assertSame(20, $first->json('data.max_uses'));
        $this->assertNull($second->json('data.max_uses'));

        $this->assertSame(2, AgentInviteLink::withoutGlobalScopes()->where('agent_id', $leader->id)->count());
    }

    // ── Form Request boundaries ────────────────────────────────────────

    public function test_both_limits_are_optional_and_an_empty_body_mints_an_unlimited_link(): void
    {
        [, $leader] = $this->companyWithLeader();

        $this->actingAs($leader)
            ->postJson('/api/v1/agent-invite-links', [])
            ->assertCreated()
            ->assertJsonPath('data.max_uses', null)
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.label', null)
            ->assertJsonPath('data.is_usable', true);
    }

    public function test_expires_at_must_be_in_the_future(): void
    {
        [, $leader] = $this->companyWithLeader();

        $this->actingAs($leader)
            ->postJson('/api/v1/agent-invite-links', ['expires_at' => now()->subDay()->toIso8601String()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');
    }

    public function test_max_uses_must_be_at_least_one(): void
    {
        [, $leader] = $this->companyWithLeader();

        // 0 uses would be a link that is unusable the instant it exists.
        $this->actingAs($leader)
            ->postJson('/api/v1/agent-invite-links', ['max_uses' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_uses');
    }

    public function test_label_is_capped_at_255_characters(): void
    {
        [, $leader] = $this->companyWithLeader();

        $this->actingAs($leader)
            ->postJson('/api/v1/agent-invite-links', ['label' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label');
    }

    /**
     * Acceptance criterion: "agent_id/company_id/used_count supplied in the
     * body are ignored." BR-6 / Section 5 rule 5 — no request value may
     * decide who owns a link or which tenant it belongs to. The Form
     * Request has no rule for these keys, so validated() drops them and the
     * Service never sees them.
     */
    public function test_server_derived_fields_supplied_in_the_body_are_ignored(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $companyA->id]);
        $victimB = User::factory()->agent()->teamLeader()->create(['company_id' => $companyB->id]);

        $response = $this->actingAs($leaderA)
            ->postJson('/api/v1/agent-invite-links', [
                'label' => 'spoof attempt',
                'agent_id' => $victimB->id,
                'company_id' => $companyB->id,
                'used_count' => 999,
                'token' => 'attacker-chosen-token',
                'revoked_at' => null,
                'max_uses' => 5,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.agent_id', $leaderA->id)
            ->assertJsonPath('data.company_id', $companyA->id)
            ->assertJsonPath('data.used_count', 0)
            // The only two client-owned values on this request did survive.
            ->assertJsonPath('data.label', 'spoof attempt')
            ->assertJsonPath('data.max_uses', 5);

        $this->assertNotSame('attacker-chosen-token', $response->json('data.token'));

        $link = AgentInviteLink::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $this->assertSame($leaderA->id, $link->agent_id);
        $this->assertSame($companyA->id, $link->company_id);
        $this->assertSame(0, $link->used_count);
        $this->assertNull($link->revoked_at);
    }

    // ── index() scoping ────────────────────────────────────────────────

    public function test_index_returns_only_the_callers_own_links(): void
    {
        $company = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $leaderB = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $mine = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leaderA->id]);
        AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leaderB->id]);

        $response = $this->actingAs($leaderA)->getJson('/api/v1/agent-invite-links')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_a_company_admin_sees_every_link_within_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $leaderB = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leaderA->id]);
        AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leaderB->id]);

        $response = $this->actingAs($admin)->getJson('/api/v1/agent-invite-links')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    /** BR-6 — TenantScope, both directions. */
    public function test_index_never_leaks_another_companys_links(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $companyA->id]);
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        AgentInviteLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $leaderA->id]);

        $response = $this->actingAs($adminB)->getJson('/api/v1/agent-invite-links')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_a_guest_cannot_touch_the_recruit_link_endpoints(): void
    {
        $this->getJson('/api/v1/agent-invite-links')->assertUnauthorized();
        $this->postJson('/api/v1/agent-invite-links', [])->assertUnauthorized();
    }

    // ── Policy: another agent's link ───────────────────────────────────

    /**
     * Acceptance criterion: "an agent cannot list, show or revoke another
     * agent's link." There is no show route in TASK-113 (see routes/api.php
     * for why), so the "show" half is asserted against the Policy ability
     * that any future show route would use — the same technique
     * AgentInviteLinkSchemaTest uses.
     */
    public function test_an_agent_cannot_view_or_revoke_another_agents_link(): void
    {
        $company = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $leaderB = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $linkB = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leaderB->id]);

        $this->assertFalse($leaderA->can('view', $linkB));

        $this->actingAs($leaderA)
            ->deleteJson("/api/v1/agent-invite-links/{$linkB->id}")
            ->assertForbidden();

        $this->assertNull($linkB->fresh()->revoked_at);
    }

    // ── destroy(): soft revoke only ────────────────────────────────────

    /**
     * Acceptance criterion: "revoke is soft." A hard delete would
     * nullOnDelete every recruit's users.recruited_via_agent_link_id and
     * erase who brought them into the company (ADR-025 §6) — so the test
     * plants a real recruit and proves the attribution still resolves after
     * the link is revoked.
     */
    public function test_revoking_a_link_is_soft_and_attribution_survives(): void
    {
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);
        $recruit = User::factory()->agent()->create([
            'company_id' => $company->id,
            'manager_id' => $leader->id,
            'recruited_via_agent_link_id' => $link->id,
        ]);

        $this->actingAs($leader)
            ->deleteJson("/api/v1/agent-invite-links/{$link->id}")
            ->assertNoContent();

        // The row survives...
        $this->assertDatabaseHas('agent_invite_links', ['id' => $link->id]);
        $fresh = $link->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->revoked_at);

        // ...and stops working, via isUsable()'s revoked_at branch.
        $this->assertFalse($fresh->isUsable());

        // ...and the recruit still points at it.
        $this->assertSame($link->id, $recruit->fresh()->recruited_via_agent_link_id);
        $this->assertTrue($fresh->recruits()->whereKey($recruit->id)->exists());
    }

    public function test_a_revoked_link_still_appears_in_the_index_marked_unusable(): void
    {
        // The leader must be able to SEE that a link is dead, not have it
        // silently vanish — TASK-116 renders a status pill from is_usable.
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->revoked()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $response = $this->actingAs($leader)->getJson('/api/v1/agent-invite-links')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.id', $link->id)
            ->assertJsonPath('data.0.is_usable', false);
        $this->assertNotNull($response->json('data.0.revoked_at'));
    }

    public function test_revoking_twice_keeps_the_first_revocation_timestamp(): void
    {
        // The revocation time is audit evidence (Section 6) — a double-tap
        // in the UI must not rewrite when the link actually stopped working.
        [$company, $leader] = $this->companyWithLeader();
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $this->actingAs($leader)->deleteJson("/api/v1/agent-invite-links/{$link->id}")->assertNoContent();
        $firstRevokedAt = $link->fresh()->revoked_at;

        $this->travel(1)->hour();

        $this->actingAs($leader)->deleteJson("/api/v1/agent-invite-links/{$link->id}")->assertNoContent();

        $this->assertTrue($firstRevokedAt->equalTo($link->fresh()->revoked_at));
    }

    public function test_a_company_admin_can_revoke_a_link_within_their_company(): void
    {
        // ADR-025 §7 — an Admin can always reverse or bound what a leader
        // did; max_uses/expires_at/revoke are the levers named there.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/agent-invite-links/{$link->id}")
            ->assertNoContent();

        $this->assertNotNull($link->fresh()->revoked_at);
    }

    // ── BR-6: cross-tenant, both directions ────────────────────────────

    public function test_a_company_admin_cannot_revoke_another_companys_link(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $companyA->id]);
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $link = AgentInviteLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $leaderA->id]);

        // 404, not 403: TenantScope removes the row from the binding query
        // entirely, so an outsider cannot even confirm the id exists (IDOR,
        // Section 5 rule 5).
        $this->actingAs($adminB)
            ->deleteJson("/api/v1/agent-invite-links/{$link->id}")
            ->assertNotFound();

        $this->assertNull($link->fresh()->revoked_at);
    }

    public function test_an_agent_cannot_revoke_a_link_in_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $leaderA = User::factory()->agent()->teamLeader()->create(['company_id' => $companyA->id]);
        $leaderB = User::factory()->agent()->teamLeader()->create(['company_id' => $companyB->id]);
        $link = AgentInviteLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $leaderA->id]);

        $this->actingAs($leaderB)
            ->deleteJson("/api/v1/agent-invite-links/{$link->id}")
            ->assertNotFound();

        $this->assertNull($link->fresh()->revoked_at);
    }
}
