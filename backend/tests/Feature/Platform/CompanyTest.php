<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// CLAUDE.md §2 "Company (Tenant)", Section 5 — a Company is the tenant
// boundary itself, so only Super Admin may create/list/update/delete
// one; Company Admin/Agent may only ever view their own single row.
class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_a_company(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/companies', ['name' => 'GENESENN Health', 'slug' => 'genesenn-health'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'genesenn-health')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_company_admin_cannot_create_a_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/companies', ['name' => 'x', 'slug' => 'x'])
            ->assertForbidden();
    }

    public function test_agent_cannot_list_companies(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/companies')->assertForbidden();
    }

    public function test_super_admin_can_list_all_companies(): void
    {
        Company::factory()->count(3)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->getJson('/api/v1/companies')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_company_admin_can_view_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);
    }

    public function test_company_admin_cannot_view_another_companys_details(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);

        $this->actingAs($admin)->getJson("/api/v1/companies/{$otherCompany->id}")->assertForbidden();
    }

    public function test_slug_must_be_unique(): void
    {
        Company::factory()->create(['slug' => 'thai-life']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/companies', ['name' => 'Another Thai Life', 'slug' => 'thai-life'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_super_admin_can_update_and_soft_delete_a_company(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/companies/{$company->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($superAdmin)->deleteJson("/api/v1/companies/{$company->id}")->assertNoContent();
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }
}
