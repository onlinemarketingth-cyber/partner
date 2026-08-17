<?php

namespace Tests\Feature\Catalog;

use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Models\Product;
use App\Models\ProductRecommendationPin;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-068 / ADR-020 decision #1 — GET /products/recommended hybrid
// assembly: admin-pinned products first, then ProductGradingService's "A"
// grade auto-fill, respecting the admin-configurable slot count (BR-7).
class ProductRecommendedTest extends TestCase
{
    use RefreshDatabase;

    /** Mark a product as "sold" (Referral reaching Complete Payment), same definition ProductGradingService uses. */
    private function markSold(Company $company, Product $product): void
    {
        $client = Client::factory()->create(['company_id' => $company->id]);
        Referral::factory()->create([
            'client_id' => $client->id,
            'company_id' => $company->id,
            'agent_id' => $client->referring_agent_id,
            'product_id' => $product->id,
            'current_stage' => PipelineStage::CompletePayment,
        ]);
    }

    public function test_recommended_returns_pinned_products_first_then_autofills_from_grade_a(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CompanyThemeSetting::create(['company_id' => $company->id, 'recommended_slot_count' => 2]);

        $pinnedProduct = Product::factory()->for($company)->create(['name' => 'Pinned Product']);
        ProductRecommendationPin::create(['company_id' => $company->id, 'product_id' => $pinnedProduct->id, 'sort_order' => 0]);

        // Three sold products with a skewed revenue split so exactly ONE
        // (gradeAProduct) lands in Pareto's first-80%-of-revenue "A"
        // bracket — see ProductGradingService's own cumulative-percent
        // grading. gradeB/gradeC must NOT appear in the auto-fill.
        $gradeAProduct = Product::factory()->for($company)->create(['name' => 'Grade A Product', 'price_satang' => 1_000_000]);
        $gradeBProduct = Product::factory()->for($company)->create(['name' => 'Grade B Product', 'price_satang' => 200_000]);
        $gradeCProduct = Product::factory()->for($company)->create(['name' => 'Grade C Product', 'price_satang' => 100_000]);
        $this->markSold($company, $gradeAProduct);
        $this->markSold($company, $gradeBProduct);
        $this->markSold($company, $gradeCProduct);

        $response = $this->actingAs($agent)->getJson('/api/v1/products/recommended')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$pinnedProduct->id, $gradeAProduct->id], $ids->all());
        $this->assertFalse($ids->contains($gradeBProduct->id));
        $this->assertFalse($ids->contains($gradeCProduct->id));
    }

    public function test_recommended_never_exceeds_the_configured_slot_count(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CompanyThemeSetting::create(['company_id' => $company->id, 'recommended_slot_count' => 1]);

        $firstPin = Product::factory()->for($company)->create();
        $secondPin = Product::factory()->for($company)->create();
        ProductRecommendationPin::create(['company_id' => $company->id, 'product_id' => $firstPin->id, 'sort_order' => 0]);
        ProductRecommendationPin::create(['company_id' => $company->id, 'product_id' => $secondPin->id, 'sort_order' => 1]);

        $response = $this->actingAs($agent)->getJson('/api/v1/products/recommended')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($firstPin->id, $response->json('data.0.id'));
    }

    public function test_recommended_defaults_to_eight_slots_when_no_theme_settings_row_exists(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        // Deliberately NO CompanyThemeSetting row — ProductRecommendationService
        // must fall back to the ADR-020 default (8), never crash/0-out.
        $pin = Product::factory()->for($company)->create();
        ProductRecommendationPin::create(['company_id' => $company->id, 'product_id' => $pin->id]);

        $response = $this->actingAs($agent)->getJson('/api/v1/products/recommended')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_recommended_never_leaks_another_companys_pinned_product(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CompanyThemeSetting::create(['company_id' => $company->id, 'recommended_slot_count' => 8]);

        $otherProduct = Product::factory()->for($otherCompany)->create();
        ProductRecommendationPin::create(['company_id' => $otherCompany->id, 'product_id' => $otherProduct->id]);

        // BR-6/Section 5 rule 5 — TenantScope on ProductRecommendationPin
        // means the pin from $otherCompany is invisible to this query at
        // all, regardless of any manipulation of the pin/grading data.
        $response = $this->actingAs($agent)->getJson('/api/v1/products/recommended')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($otherProduct->id));
    }
}
