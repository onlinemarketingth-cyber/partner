<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Manage Agents" (Phase 7, human-confirmed scope): Company Admin
// manages team members (agent + company_admin) within their own
// company only. No email/invite system exists — Admin types a
// temporary password directly (StoreUserRequest/ResetUserPasswordRequest).
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_list_or_manage_other_users(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_company_admin_can_create_a_new_agent_with_a_temporary_password(): void
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
            ->assertJsonPath('data.role', 'agent')
            ->assertJsonPath('data.company.id', $company->id);

        $this->assertDatabaseHas('users', ['email' => 'somchai@thailife.test', 'company_id' => $company->id, 'role' => 'agent']);
    }

    public function test_company_admin_cannot_set_company_id_when_creating_a_user(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'company_id' => $otherCompany->id,
                'first_name' => 'x', 'last_name' => 'y', 'email' => 'x@thailife.test', 'password' => 'password123', 'role' => 'agent',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_company_admin_cannot_create_a_super_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'first_name' => 'x', 'last_name' => 'y', 'email' => 'x@thailife.test', 'password' => 'password123', 'role' => 'super_admin',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_company_admin_cannot_promote_an_agent_to_super_admin(): void
    {
        // Same restriction as creation (test above), exercised on the
        // update path too — Rule::in(['agent','company_admin']) in
        // UpdateUserRequest structurally excludes super_admin, not just
        // filtered post-hoc.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['role' => 'super_admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('agent', $agent->fresh()->role->value);
    }

    public function test_super_admin_must_specify_company_id_when_creating_a_user(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/users', [
                'first_name' => 'x', 'last_name' => 'y', 'email' => 'x@thailife.test', 'password' => 'password123', 'role' => 'agent',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_company_admin_can_promote_an_agent_to_company_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['role' => 'company_admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'company_admin');
    }

    public function test_cross_company_user_access_is_404(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($admin)->getJson("/api/v1/users/{$foreignAgent->id}")->assertNotFound();
    }

    public function test_super_admin_row_is_never_visible_through_this_endpoint(): void
    {
        $anotherSuperAdmin = User::factory()->superAdmin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->getJson("/api/v1/users/{$anotherSuperAdmin->id}")->assertForbidden();
    }

    public function test_super_admin_row_is_excluded_from_the_index_list(): void
    {
        $company = Company::factory()->create();
        User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->superAdmin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/users')->assertOk();
        $roles = collect($response->json('data'))->pluck('role');
        $this->assertFalse($roles->contains('super_admin'));
    }

    public function test_company_admin_can_deactivate_an_agent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$agent->id}")->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $agent->id]);
        $this->actingAs($admin)->getJson('/api/v1/users')->assertJsonMissing(['id' => $agent->id]);
    }

    public function test_company_admin_cannot_deactivate_themselves(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$admin->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_a_deactivated_agent_can_be_restored(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent->delete();

        $this->actingAs($admin)
            ->postJson("/api/v1/users/{$agent->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('users', ['id' => $agent->id, 'deleted_at' => null]);
    }

    public function test_company_admin_can_reset_an_agents_password(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $oldHash = $agent->password;

        $this->actingAs($admin)
            ->postJson("/api/v1/users/{$agent->id}/reset-password", ['password' => 'NewTempPass456'])
            ->assertOk();

        $this->assertNotSame($oldHash, $agent->fresh()->password);
    }

    public function test_agent_cannot_reset_a_colleagues_password(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agentA)
            ->postJson("/api/v1/users/{$agentB->id}/reset-password", ['password' => 'whatever123'])
            ->assertForbidden();
    }
}
