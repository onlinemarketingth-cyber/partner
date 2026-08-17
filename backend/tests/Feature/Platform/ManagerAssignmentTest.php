<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-025 — manager_id assignment via the existing "Manage Agents"
// PUT /users/{user} endpoint (UpdateUserRequest + UserService::
// assertValidManager()). BR-6: same-company only; no management cycles.
class ManagerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_assign_a_manager_within_the_same_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['manager_id' => $manager->id])
            ->assertOk()
            ->assertJsonPath('data.manager_id', $manager->id);

        $this->assertSame($manager->id, $agent->fresh()->manager_id);
    }

    public function test_manager_id_can_be_cleared_back_to_null(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['manager_id' => null])
            ->assertOk()
            ->assertJsonPath('data.manager_id', null);
    }

    public function test_assigning_a_manager_from_another_company_is_rejected(): void
    {
        // BR-6 — a manager relationship must never cross tenants, even
        // though `exists:users,id` alone would happily accept the id
        // (that rule only checks the row exists anywhere, not which
        // company it belongs to) — this is exactly why the real check
        // lives in UserService::assertValidManager(), not the FormRequest.
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $agent = User::factory()->agent()->create(['company_id' => $ownCompany->id]);
        $foreignManager = User::factory()->agent()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['manager_id' => $foreignManager->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_id');

        $this->assertNull($agent->fresh()->manager_id);
    }

    public function test_assigning_a_manager_that_would_create_a_cycle_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $agentA->id]);

        // agentB already reports to agentA — making agentA report to
        // agentB would close the loop (A -> B -> A).
        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agentA->id}", ['manager_id' => $agentB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_id');

        $this->assertNull($agentA->fresh()->manager_id);
    }

    public function test_an_agent_cannot_be_set_as_their_own_manager(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['manager_id' => $agent->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_id');
    }

    public function test_a_three_level_chain_is_a_valid_assignment(): void
    {
        // Multi-level (Round 2 decision) — no artificial depth cap.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $branchManager = User::factory()->agent()->create(['company_id' => $company->id]);
        $unitManager = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $branchManager->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['manager_id' => $unitManager->id])
            ->assertOk();

        $this->assertSame($unitManager->id, $agent->fresh()->manager_id);
        $this->assertSame($branchManager->id, $unitManager->fresh()->manager_id);
    }
}
