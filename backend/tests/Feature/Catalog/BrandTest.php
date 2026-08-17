<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// CLAUDE.md Section 5 rule 5 + Definition of Done: every endpoint must
// have tenant-isolation test cases, cross-tenant access expected to
// 403/404.
class BrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_list_brands_in_their_own_company(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        Brand::factory()->for($company)->create();

        $this->actingAs($agent)
            ->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_agent_cannot_create_a_brand(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/brands', ['name' => 'New Brand'])
            ->assertForbidden();
    }

    public function test_company_admin_can_create_a_brand_in_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/brands', ['name' => 'New Brand'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_company_admin_cannot_view_another_companys_brand(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $otherBrand = Brand::factory()->for($otherCompany)->create();

        // TenantScope filters the query used for route-model-binding —
        // a cross-tenant ID never resolves, so this 404s before the
        // Policy even runs (BR-6 rule 5, IDOR guard).
        $this->actingAs($admin)
            ->getJson("/api/v1/brands/{$otherBrand->id}")
            ->assertNotFound();
    }

    public function test_company_admin_cannot_update_another_companys_brand(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $otherBrand = Brand::factory()->for($otherCompany)->create();

        $this->actingAs($admin)
            ->putJson("/api/v1/brands/{$otherBrand->id}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    public function test_super_admin_can_create_a_brand_for_any_company(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/brands', ['name' => 'Cross-company brand', 'company_id' => $company->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/brands')->assertUnauthorized();
    }
}
