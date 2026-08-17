<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_list_modules_but_cannot_create_one(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        Module::factory()->for($company)->create();

        $this->actingAs($agent)->getJson('/api/v1/modules')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($agent)
            ->postJson('/api/v1/modules', ['cert_tier_id' => CertTier::factory()->create()->id, 'title' => 'x'])
            ->assertForbidden();
    }

    public function test_company_admin_can_create_a_module_tied_to_a_product(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $product = \App\Models\Product::factory()->for($company)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/modules', [
                'cert_tier_id' => $tier->id,
                'product_id' => $product->id,
                'title' => 'Intro to product',
            ])
            ->assertCreated()
            ->assertJsonPath('data.product.id', $product->id);
    }

    public function test_cross_tenant_module_access_is_404(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignModule = Module::factory()->for($otherCompany)->create();

        $this->actingAs($admin)->getJson("/api/v1/modules/{$foreignModule->id}")->assertNotFound();
    }

    // ADR-009 — a Section (Module) exposes its Lessons inline; every
    // pre-existing content-item CRUD moved to ModuleLessonController.
    public function test_company_admin_can_update_and_delete_a_lesson(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $module = Module::factory()->for($company)->create();
        $lesson = ModuleLesson::factory()->for($company)->create(['module_id' => $module->id, 'title' => 'Old title']);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", ['title' => 'New title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New title');

        $this->actingAs($admin)->deleteJson("/api/v1/module-lessons/{$lesson->id}")->assertNoContent();
        // ModuleLesson uses SoftDeletes — the row still physically exists
        // (deleted_at set), so assertModelMissing() (a raw table check)
        // would fail; assertSoftDeleted() is the correct assertion here.
        $this->assertSoftDeleted($lesson);
    }

    public function test_agent_cannot_update_or_delete_a_lesson(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $module = Module::factory()->for($company)->create();
        $lesson = ModuleLesson::factory()->for($company)->create(['module_id' => $module->id]);

        $this->actingAs($agent)->putJson("/api/v1/module-lessons/{$lesson->id}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($agent)->deleteJson("/api/v1/module-lessons/{$lesson->id}")->assertForbidden();
    }

    public function test_company_admin_cannot_add_a_lesson_to_another_companys_module(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignModule = Module::factory()->for($otherCompany)->create();

        // TenantScope filters the {module} route-model-binding query
        // itself (Company Admin is scoped to their own company_id), so a
        // foreign company's module 404s before StoreModuleLessonRequest
        // ::authorize() is ever reached — same convention as
        // test_cross_tenant_module_access_is_404 above.
        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$foreignModule->id}/lessons", ['title' => 'x', 'content_type' => 'pdf', 'content_ref' => 'https://x.test'])
            ->assertNotFound();
    }
}
