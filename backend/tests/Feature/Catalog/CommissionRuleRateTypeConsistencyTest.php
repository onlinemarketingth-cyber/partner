<?php

namespace Tests\Feature\Catalog;

use App\Enums\CommissionRateType;
use App\Models\CertTier;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-197 §2.2/§2.4 — hoisting the %/fixed-amount FORMAT from a per-rule
// field to a per-product setting (products.commission_rate_type). Every
// product here is priced well above any rate_value used (Product factory
// default is 5,000-15,000 THB) so nothing in this file accidentally trips
// TASK-196's separate commission-rate-cap check.
class CommissionRuleRateTypeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdminAndProduct(): array
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        return [$admin, $product];
    }

    // -----------------------------------------------------------------
    // §2.2 edge case (a) — the first rule for a product decides the
    // format and stamps it onto products.commission_rate_type.
    // -----------------------------------------------------------------

    public function test_a_products_first_rule_auto_sets_commission_rate_type(): void
    {
        [$admin, $product] = $this->makeAdminAndProduct();
        $tier = CertTier::factory()->create();

        $this->assertNull($product->fresh()->commission_rate_type);

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::FixedSatang->value,
            'rate_value' => 500,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame(
            CommissionRateType::FixedSatang,
            $product->fresh()->commission_rate_type,
        );
    }

    // -----------------------------------------------------------------
    // §2.2 — a second rule for the SAME product (different cert tier, so
    // it doesn't also trip the overlap check) with a MISMATCHED rate_type
    // is rejected with 422, and the product's locked-in format is
    // untouched by the rejected attempt.
    // -----------------------------------------------------------------

    public function test_a_second_rule_for_the_same_product_with_a_different_rate_type_is_rejected(): void
    {
        [$admin, $product] = $this->makeAdminAndProduct();
        $basicTier = CertTier::factory()->create();
        $highTier = CertTier::factory()->create();

        // First rule locks the product into 'percentage'.
        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $basicTier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 500,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $highTier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::FixedSatang->value,
            'rate_value' => 500,
            'effective_from' => now()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate_type');

        $this->assertSame(
            CommissionRateType::Percentage,
            $product->fresh()->commission_rate_type,
        );

        // A rule for the SAME cert tier + matching rate_type is still
        // fine — confirms the rejection above was specifically about the
        // mismatch, not some other side effect of the second POST.
        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $highTier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 700,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();
    }

    // -----------------------------------------------------------------
    // Same enforcement on the update (PUT) path, against the rule's own
    // immutable product_id.
    // -----------------------------------------------------------------

    public function test_updating_a_rule_to_a_mismatched_rate_type_is_rejected(): void
    {
        [$admin, $product] = $this->makeAdminAndProduct();
        $tier = CertTier::factory()->create();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 500,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $secondTier = CertTier::factory()->create();
        $rule = CommissionRule::factory()->create([
            'company_id' => $admin->company_id,
            'cert_tier_id' => $secondTier->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 600,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/commission-rules/{$rule->id}", [
                'rate_type' => CommissionRateType::FixedSatang->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate_type');
    }

    // -----------------------------------------------------------------
    // §1/§2.2 — category-scoped and company-wide rules are completely
    // exempt: no single product to hoist a format onto.
    // -----------------------------------------------------------------

    public function test_a_category_scoped_rule_can_freely_use_any_rate_type_regardless_of_a_products_setting(): void
    {
        [$admin, $product] = $this->makeAdminAndProduct();
        $tier = CertTier::factory()->create();
        $category = ProductCategory::factory()->for($product->company)->create();

        // Lock the product itself into 'percentage'.
        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 500,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        // A category-scoped rule (different cert tier, no relation to
        // this test's product other than sharing a company) is free to
        // pick fixed_satang even though the product above is 'percentage'.
        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => CertTier::factory()->create()->id,
            'product_category_id' => $category->id,
            'rate_type' => CommissionRateType::FixedSatang->value,
            'rate_value' => 500,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();
    }

    public function test_a_company_wide_rule_can_freely_use_any_rate_type_regardless_of_a_products_setting(): void
    {
        [$admin, $product] = $this->makeAdminAndProduct();
        $tier = CertTier::factory()->create();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 500,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        // Neither product_id nor product_category_id set = company-wide
        // default, free to be fixed_satang.
        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => CertTier::factory()->create()->id,
            'rate_type' => CommissionRateType::FixedSatang->value,
            'rate_value' => 500,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();
    }

    // -----------------------------------------------------------------
    // §1/§2.4 — no migration backfills or mutates historical rows. A
    // product that already has mixed rate_type rules from before this
    // task shipped keeps them exactly as-is, and the product's own
    // commission_rate_type stays null (nothing auto-populates it
    // retroactively — only a NEW rule creation via the Service does).
    // -----------------------------------------------------------------

    public function test_pre_existing_mixed_rate_type_rows_for_one_product_are_left_untouched(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create();
        $tierA = CertTier::factory()->create();
        $tierB = CertTier::factory()->create();

        // Seeded directly (bypassing CommissionRuleService), simulating
        // rows that existed before this task's enforcement/side-effect
        // shipped — exactly the "legacy mixed data" scenario §1 promises
        // never gets rewritten.
        $percentageRule = CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tierA->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 500,
        ]);
        $fixedRule = CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tierB->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::FixedSatang,
            'rate_value' => 500,
        ]);

        $this->assertNull($product->fresh()->commission_rate_type);
        $this->assertSame(CommissionRateType::Percentage, $percentageRule->fresh()->rate_type);
        $this->assertSame(CommissionRateType::FixedSatang, $fixedRule->fresh()->rate_type);
    }
}
