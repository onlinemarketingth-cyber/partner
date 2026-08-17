<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductRecommendationPin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-068 / ADR-020 row 4.
class ProductRecommendationPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_pin_their_own_product(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/product-recommendation-pins', [
            'product_id' => $product->id,
            'sort_order' => 1,
        ])->assertCreated();

        $this->assertSame($company->id, $response->json('data.company_id'));
        $this->assertSame($product->id, $response->json('data.product_id'));
    }

    public function test_pin_product_id_must_belong_to_the_same_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $foreignProduct = Product::factory()->for($otherCompany)->create();

        // BR-6 — never trust the client to only submit its own tenant's IDs.
        $this->actingAs($admin)->postJson('/api/v1/product-recommendation-pins', [
            'product_id' => $foreignProduct->id,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_pinning_the_same_product_twice_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();
        ProductRecommendationPin::create(['company_id' => $company->id, 'product_id' => $product->id]);

        $this->actingAs($admin)->postJson('/api/v1/product-recommendation-pins', [
            'product_id' => $product->id,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_agent_can_read_pins_but_cannot_create_one(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();
        ProductRecommendationPin::create(['company_id' => $company->id, 'product_id' => $product->id]);

        $this->actingAs($agent)->getJson('/api/v1/product-recommendation-pins')->assertOk();

        $this->actingAs($agent)->postJson('/api/v1/product-recommendation-pins', [
            'product_id' => $product->id,
        ])->assertStatus(403);
    }

    public function test_cross_company_pin_access_is_rejected(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $otherProduct = Product::factory()->for($otherCompany)->create();
        $foreignPin = ProductRecommendationPin::create(['company_id' => $otherCompany->id, 'product_id' => $otherProduct->id]);

        $this->actingAs($admin)->getJson("/api/v1/product-recommendation-pins/{$foreignPin->id}")->assertNotFound();
        $this->actingAs($admin)->putJson("/api/v1/product-recommendation-pins/{$foreignPin->id}", ['sort_order' => 5])->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/v1/product-recommendation-pins/{$foreignPin->id}")->assertNotFound();
    }
}
