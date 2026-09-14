<?php

namespace Tests\Feature\Commission;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-14 — "เลือกครบแล้วทำไมขึ้นแบบนี้" (owner, from the screen).
 *
 * He picked "Anti Aging" from the dropdown, the impact preview listed the
 * three products the rate would change with their new amounts, and บันทึก
 * answered *"The selected product category id is invalid."*
 *
 * The category is PLATFORM-OWNED — `company_id NULL`, ADR-040 §1 — because
 * the GENESENN products in it are: one central product row cannot point at a
 * per-company taxonomy. Every commission Form Request demanded
 * `where('company_id', $companyId)`, so a category-scoped commission rate was
 * IMPOSSIBLE to save for any shared catalogue. Not hard. Impossible.
 *
 * This is the same defect ValidatesProductOwnership was written for one field
 * over — "the list offers what the save refuses" — and the cure
 * (ValidatesProductTaxonomy::taxonomyRule) already existed and had simply not
 * been adopted here.
 *
 * The tests below pin both halves: the platform category is accepted, and
 * ANOTHER COMPANY'S category is still refused, which is what the original
 * hand-written rule was protecting and what a careless widening would lose.
 */
class PlatformCategoryRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_agent_rate_can_be_scoped_to_a_platform_category(): void
    {
        [$company, $platformCategory] = $this->platformWorld();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-rules', [
                'company_id' => $company->id,
                'product_category_id' => $platformCategory->id,
                'rate_type' => 'percentage',
                'rate_value' => 500,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_a_leader_rate_can_be_scoped_to_a_platform_category(): void
    {
        [$company, $platformCategory] = $this->platformWorld();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'product_category_id' => $platformCategory->id,
                'rate_type' => 'percentage',
                'rate_value' => 200,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_the_preview_accepts_exactly_what_the_save_accepts(): void
    {
        /*
         * THE TEST THAT WOULD HAVE CAUGHT THIS ON THE DAY.
         *
         * The preview used a bare `exists:product_categories,id` while the save
         * demanded the company match, so the panel cheerfully reported three
         * products about to change in front of a door that was closed. A
         * preview looser than its own write is a green light on a red signal.
         */
        [$company, $platformCategory] = $this->platformWorld();
        $admin = User::factory()->superAdmin()->create();

        $payload = [
            'company_id' => $company->id,
            'product_category_id' => $platformCategory->id,
            'rate_type' => 'percentage',
            'rate_value' => 500,
        ];

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-rate-impact', $payload + ['kind' => 'agent'])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-rules', $payload + ['effective_from' => now()->toDateString()])
            ->assertCreated();
    }

    public function test_another_companys_category_is_still_refused(): void
    {
        // The reason the strict rule existed. Widening to platform rows must
        // not widen to a neighbour's taxonomy (BR-6).
        [$company] = $this->platformWorld();
        $theirs = ProductCategory::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-rules', [
                'company_id' => $company->id,
                'product_category_id' => $theirs->id,
                'rate_type' => 'percentage',
                'rate_value' => 500,
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_category_id');
    }

    /** @return array{0: Company, 1: ProductCategory} */
    private function platformWorld(): array
    {
        $company = Company::factory()->create();
        // company_id NULL on both — a central product in a central category,
        // exactly the shape ADR-040 introduced and the screen was showing.
        $platformCategory = ProductCategory::factory()->create(['company_id' => null]);
        Product::factory()->create([
            'company_id' => null,
            'category_id' => $platformCategory->id,
            'price_satang' => 1000000,
        ]);

        return [$company, $platformCategory];
    }
}
