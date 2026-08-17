<?php

namespace Tests\Feature\Customer;

use App\Models\ClientCategory;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-056 Sprint P2 — client segmentation (BR-7 config). Mirrors
// BrandTest's shape exactly (same viewAny/manage-only rule).
class ClientCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_list_categories_in_their_own_company(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        ClientCategory::factory()->for($company)->create();

        $this->actingAs($agent)
            ->getJson('/api/v1/client-categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_self_heals_the_default_starter_set_when_company_has_none(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        // Deliberately no ClientCategory rows created — first visit.

        $response = $this->actingAs($agent)->getJson('/api/v1/client-categories')->assertOk();

        $this->assertCount(4, $response->json('data'));
        $this->assertDatabaseCount('client_categories', 4);
    }

    public function test_index_does_not_reseed_after_an_admin_deletes_all_categories(): void
    {
        // An admin who deliberately deleted every category wanted zero,
        // not a silent respawn — ensureDefaults() only fires when the
        // company has NEVER had a row, not "currently has none".
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->actingAs($agent)->getJson('/api/v1/client-categories'); // triggers the seed
        ClientCategory::query()->delete();

        $response = $this->actingAs($agent)->getJson('/api/v1/client-categories')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_agent_cannot_create_a_category(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/client-categories', ['name' => 'New Category'])
            ->assertForbidden();
    }

    public function test_company_admin_can_create_a_category_in_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/client-categories', ['name' => 'ลูกค้า Premium'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_company_admin_cannot_update_another_companys_category(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $otherCategory = ClientCategory::factory()->for($otherCompany)->create();

        $this->actingAs($admin)
            ->putJson("/api/v1/client-categories/{$otherCategory->id}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    public function test_super_admin_can_create_a_category_for_any_company(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/client-categories', ['name' => 'Cross-company category', 'company_id' => $company->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/client-categories')->assertUnauthorized();
    }
}
