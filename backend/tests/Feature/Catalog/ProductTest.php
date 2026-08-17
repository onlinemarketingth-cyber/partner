<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_create_a_product_with_a_valid_integer_price(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $brand = Brand::factory()->for($company)->create();
        $category = ProductCategory::factory()->for($company)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/products', [
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'name' => 'Standard Package',
                'price_satang' => 890000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.price_satang', 890000);
    }

    public function test_price_satang_rejects_a_float(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $brand = Brand::factory()->for($company)->create();
        $category = ProductCategory::factory()->for($company)->create();

        // BR-3: money is an integer, never a float.
        $this->actingAs($admin)
            ->postJson('/api/v1/products', [
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'name' => 'Bad Package',
                'price_satang' => 8900.50,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price_satang');
    }

    public function test_price_satang_rejects_a_negative_value(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $brand = Brand::factory()->for($company)->create();
        $category = ProductCategory::factory()->for($company)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/products', [
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'name' => 'Bad Package',
                'price_satang' => -100,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price_satang');
    }

    public function test_cannot_create_a_product_with_another_companys_brand(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $foreignBrand = Brand::factory()->for($otherCompany)->create();
        $category = ProductCategory::factory()->for($company)->create();

        // BR-6 — never trust the client to only submit its own tenant's IDs.
        $this->actingAs($admin)
            ->postJson('/api/v1/products', [
                'brand_id' => $foreignBrand->id,
                'category_id' => $category->id,
                'name' => 'Cross-tenant product',
                'price_satang' => 890000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('brand_id');
    }

    // ADR-011/TASK-027 — plan-type company-default + product-override.

    public function test_a_product_with_no_override_inherits_the_companys_plan_type(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => 'binary']);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $brand = Brand::factory()->for($company)->create();
        $category = ProductCategory::factory()->for($company)->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/products', [
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'name' => 'Inherits company default',
                'price_satang' => 890000,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.commission_plan_type', null);
        $response->assertJsonPath('data.effective_plan_type', 'binary');
    }

    public function test_a_product_can_override_the_companys_plan_type(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => 'unilevel']);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $brand = Brand::factory()->for($company)->create();
        $category = ProductCategory::factory()->for($company)->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/products', [
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'name' => 'Affiliate product',
                'price_satang' => 890000,
                'commission_plan_type' => 'affiliate',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.commission_plan_type', 'affiliate');
        $response->assertJsonPath('data.effective_plan_type', 'affiliate');
    }

    public function test_commission_plan_type_rejects_an_invalid_value(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $brand = Brand::factory()->for($company)->create();
        $category = ProductCategory::factory()->for($company)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/products', [
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'name' => 'Bad plan type',
                'price_satang' => 890000,
                'commission_plan_type' => 'not_a_real_plan_type',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commission_plan_type');
    }

    public function test_updating_a_product_can_set_and_then_clear_the_plan_type_override(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => 'unilevel']);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $brand = Brand::factory()->for($company)->create();
        $category = ProductCategory::factory()->for($company)->create();
        $product = \App\Models\Product::factory()->for($company)->create(['brand_id' => $brand->id, 'category_id' => $category->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$product->id}", ['commission_plan_type' => 'matrix'])
            ->assertOk()
            ->assertJsonPath('data.effective_plan_type', 'matrix');

        // Explicit null clears the override back to "inherit company default".
        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$product->id}", ['commission_plan_type' => null])
            ->assertOk()
            ->assertJsonPath('data.commission_plan_type', null)
            ->assertJsonPath('data.effective_plan_type', 'unilevel');
    }

    public function test_cross_tenant_product_update_is_rejected(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $brand = Brand::factory()->for($otherCompany)->create();
        $category = ProductCategory::factory()->for($otherCompany)->create();
        $foreignProduct = \App\Models\Product::factory()->for($otherCompany)->create(['brand_id' => $brand->id, 'category_id' => $category->id]);

        // TenantScope filters the {product} route-model-binding query
        // itself (Company Admin is scoped to their own company_id), so a
        // foreign company's product 404s before UpdateProductRequest
        // ::authorize() is ever reached — same convention as every other
        // cross-tenant lookup in this app (Section 5 rule 5).
        $this->actingAs($admin)
            ->putJson("/api/v1/products/{$foreignProduct->id}", ['commission_plan_type' => 'affiliate'])
            ->assertNotFound();
    }
}
