<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-068 / ADR-020 row 3 — server-side icon whitelist
// (App\Support\CuratedIcons), mirroring the frontend picker's curated set.
class ProductCategoryIconTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_category_with_a_whitelisted_icon_succeeds(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->postJson('/api/v1/product-categories', [
            'name' => 'ตรวจสุขภาพ',
            'icon' => 'trophy',
        ])->assertCreated();

        $response->assertJsonPath('data.icon', 'trophy');
    }

    public function test_creating_a_category_with_a_non_whitelisted_icon_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // Server-side rejection — unlike nav_icon_overrides (deliberately
        // unwhitelisted), this renders on the public storefront (ADR-020).
        $this->actingAs($admin)->postJson('/api/v1/product-categories', [
            'name' => 'ตรวจสุขภาพ',
            'icon' => 'not_a_real_icon; DROP TABLE',
        ])->assertStatus(422)->assertJsonValidationErrors('icon');
    }

    public function test_updating_a_categorys_icon_to_a_non_whitelisted_value_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $category = ProductCategory::factory()->for($company)->create();

        $this->actingAs($admin)->putJson("/api/v1/product-categories/{$category->id}", [
            'icon' => 'totally_made_up_icon',
        ])->assertStatus(422)->assertJsonValidationErrors('icon');
    }

    public function test_updating_a_categorys_icon_to_null_clears_it(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $category = ProductCategory::factory()->for($company)->create(['icon' => 'star']);

        $this->actingAs($admin)->putJson("/api/v1/product-categories/{$category->id}", [
            'icon' => null,
        ])->assertOk()->assertJsonPath('data.icon', null);

        $this->assertNull($category->refresh()->icon);
    }
}
