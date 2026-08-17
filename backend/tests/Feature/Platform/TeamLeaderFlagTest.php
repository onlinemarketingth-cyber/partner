<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-112 / ADR-025 §1 — `users.is_team_leader`, the admin-granted
 * "may mint recruit links and approve their own recruits" flag.
 *
 * Covers TASK-112 acceptance criterion: "an Agent cannot set
 * is_team_leader on themselves or anyone else."
 *
 * What is NOT tested here (and deliberately so): what the flag actually
 * unlocks. Minting is TASK-113, registration is TASK-114, approval is
 * TASK-115. This file only proves who may turn the bit on.
 */
class TeamLeaderFlagTest extends TestCase
{
    use RefreshDatabase;

    // ── Default ────────────────────────────────────────────────────────

    /** Acceptance: the flag defaults false — nobody is a leader by accident. */
    public function test_is_team_leader_defaults_to_false_for_a_newly_created_user(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'first_name' => 'Somchai',
                'last_name' => 'Jaidee',
                'email' => 'somchai@thailife.test',
                'password' => 'TempPass123',
                'role' => 'agent',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_team_leader', false);

        $this->assertFalse(User::where('email', 'somchai@thailife.test')->first()->is_team_leader);
    }

    /**
     * ADR-025 §1 / TASK-112 item 5 — is_team_leader is deliberately absent
     * from StoreUserRequest, so it can never be granted at creation time.
     * The controller passes only validated() through, which silently drops
     * the key; the assertion below is what makes that silence intentional
     * rather than an accident nobody would notice.
     */
    public function test_is_team_leader_cannot_be_granted_at_creation_time_even_by_an_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'first_name' => 'Somchai',
                'last_name' => 'Jaidee',
                'email' => 'leader-at-birth@thailife.test',
                'password' => 'TempPass123',
                'role' => 'agent',
                'is_team_leader' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_team_leader', false);

        $this->assertFalse(User::where('email', 'leader-at-birth@thailife.test')->first()->is_team_leader);
    }

    // ── Who may grant it ───────────────────────────────────────────────

    public function test_company_admin_can_designate_an_agent_as_a_team_leader(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['is_team_leader' => true])
            ->assertOk()
            ->assertJsonPath('data.is_team_leader', true)
            // ADR-025 §1 — a flag, NOT a fourth role: role must be untouched.
            ->assertJsonPath('data.role', 'agent');

        $this->assertTrue($agent->fresh()->is_team_leader);
    }

    public function test_company_admin_can_revoke_the_team_leader_flag(): void
    {
        // ADR-025 §7's residual-risk mitigation depends on the flag being
        // revocable, so the "off" direction is tested as explicitly as "on".
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$leader->id}", ['is_team_leader' => false])
            ->assertOk()
            ->assertJsonPath('data.is_team_leader', false);

        $this->assertFalse($leader->fresh()->is_team_leader);
    }

    public function test_super_admin_can_designate_a_team_leader_in_any_company(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/users/{$agent->id}", ['is_team_leader' => true])
            ->assertOk()
            ->assertJsonPath('data.is_team_leader', true);

        $this->assertTrue($agent->fresh()->is_team_leader);
    }

    // ── Who may NOT grant it (acceptance criterion) ────────────────────

    public function test_agent_cannot_make_themselves_a_team_leader(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->putJson("/api/v1/users/{$agent->id}", ['is_team_leader' => true])
            ->assertForbidden();

        $this->assertFalse($agent->fresh()->is_team_leader);
    }

    public function test_agent_cannot_make_another_agent_a_team_leader(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agentA)
            ->putJson("/api/v1/users/{$agentB->id}", ['is_team_leader' => true])
            ->assertForbidden();

        $this->assertFalse($agentB->fresh()->is_team_leader);
    }

    public function test_an_existing_team_leader_still_cannot_promote_anyone(): void
    {
        // ADR-025 §2 — the flag grants recruiting, NOT administration.
        // A leader must never be able to mint more leaders; that would turn
        // a single admin mistake into an unbounded chain.
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $recruit = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $this->actingAs($leader)
            ->putJson("/api/v1/users/{$recruit->id}", ['is_team_leader' => true])
            ->assertForbidden();

        $this->assertFalse($recruit->fresh()->is_team_leader);
    }

    public function test_company_admin_cannot_designate_a_leader_in_another_company(): void
    {
        // BR-6 / Section 5 rule 5 — cross-tenant IDOR guard.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminB)
            ->putJson("/api/v1/users/{$agentA->id}", ['is_team_leader' => true])
            // TenantScope hides the row entirely from adminB's route-model
            // binding, so this is a 404 rather than a 403 — same shape as
            // every other cross-tenant assertion in this suite.
            ->assertNotFound();

        $this->assertFalse($agentA->fresh()->is_team_leader);
    }

    public function test_self_service_profile_endpoint_cannot_set_the_flag(): void
    {
        // The obvious back door: an Agent legitimately CAN edit their own
        // profile. UpdateNameRequest never validates is_team_leader, so
        // validated() drops it before the Service ever sees it.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/name', [
                'first_name' => 'Somchai',
                'last_name' => 'Jaidee',
                'is_team_leader' => true,
            ])
            ->assertOk();

        $this->assertFalse($agent->fresh()->is_team_leader);
    }
}
