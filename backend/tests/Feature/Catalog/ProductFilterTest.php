<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-068 / ADR-020 row 1 — GET /products category_id/brand_id/
// price_min_satang/price_max_satang filters, additive to the existing
// q/is_active/pagination behaviour (unchanged for callers not using them).
class ProductFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_by_category_id(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $categoryA = ProductCategory::factory()->for($company)->create();
        $categoryB = ProductCategory::factory()->for($company)->create();
        $matching = Product::factory()->for($company)->create(['category_id' => $categoryA->id]);
        Product::factory()->for($company)->create(['category_id' => $categoryB->id]);

        $response = $this->actingAs($agent)->getJson("/api/v1/products?category_id={$categoryA->id}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$matching->id], $ids->all());
    }

    public function test_filters_by_brand_id(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $brandA = Brand::factory()->for($company)->create();
        $brandB = Brand::factory()->for($company)->create();
        $matching = Product::factory()->for($company)->create(['brand_id' => $brandA->id]);
        Product::factory()->for($company)->create(['brand_id' => $brandB->id]);

        $response = $this->actingAs($agent)->getJson("/api/v1/products?brand_id={$brandA->id}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$matching->id], $ids->all());
    }

    public function test_filters_by_price_range(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tooCheap = Product::factory()->for($company)->create(['price_satang' => 100_000]);
        $inRange = Product::factory()->for($company)->create(['price_satang' => 890_000]);
        $tooExpensive = Product::factory()->for($company)->create(['price_satang' => 2_000_000]);

        $response = $this->actingAs($agent)
            ->getJson('/api/v1/products?price_min_satang=500000&price_max_satang=1000000')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($inRange->id));
        $this->assertFalse($ids->contains($tooCheap->id));
        $this->assertFalse($ids->contains($tooExpensive->id));
    }

    public function test_combining_category_brand_and_price_filters(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $category = ProductCategory::factory()->for($company)->create();
        $brand = Brand::factory()->for($company)->create();
        $matching = Product::factory()->for($company)->create([
            'category_id' => $category->id, 'brand_id' => $brand->id, 'price_satang' => 890_000,
        ]);
        // Same category+brand but outside the price range — must be excluded.
        Product::factory()->for($company)->create([
            'category_id' => $category->id, 'brand_id' => $brand->id, 'price_satang' => 5_000_000,
        ]);

        $response = $this->actingAs($agent)->getJson(
            "/api/v1/products?category_id={$category->id}&brand_id={$brand->id}&price_min_satang=500000&price_max_satang=1000000"
        )->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$matching->id], $ids->all());
    }

    public function test_price_min_max_reject_non_integer_values(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // BR-3 — money filters are integers only, never a float.
        $this->actingAs($agent)
            ->getJson('/api/v1/products?price_min_satang=890.50')
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_min_satang');
    }

    public function test_existing_q_and_is_active_filters_still_work_unchanged(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $matching = Product::factory()->for($company)->create(['name' => 'Premium Health Package', 'is_active' => true]);
        Product::factory()->for($company)->create(['name' => 'Basic Package', 'is_active' => false]);

        $response = $this->actingAs($agent)
            ->getJson('/api/v1/products?q=Premium&is_active=1')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$matching->id], $ids->all());
    }
}
