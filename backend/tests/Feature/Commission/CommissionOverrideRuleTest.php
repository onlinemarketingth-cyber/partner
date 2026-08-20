<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\CertTier;
use App\Models\CommissionOverrideRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-025 / ADR-006 — mirrors tests/Feature/Catalog/CommissionRuleTest.php,
// same access-control shape (Agent excluded entirely, sensitive
// compensation config).
class CommissionOverrideRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_commission_override_rules(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/commission-override-rules')
            ->assertForbidden();
    }

    public function test_company_admin_can_create_a_commission_override_rule(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $managerTier = CertTier::factory()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-override-rules', [
                'manager_cert_tier_id' => $managerTier->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 200,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated()
            // JsonResource responses wrap in a `data` envelope by default
            // (same as UserManagementTest's `assertJsonPath('data.role', ...)`
            // — bug in this test, not the API, on the first run: this was
            // originally written without the `data.` prefix).
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_overlapping_effective_date_range_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $managerTier = CertTier::factory()->create();

        $this->actingAs($admin)->postJson('/api/v1/commission-override-rules', [
            'manager_cert_tier_id' => $managerTier->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 200,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ])->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-override-rules', [
                'manager_cert_tier_id' => $managerTier->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 300,
                'effective_from' => '2026-06-01',
                'effective_to' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_from');
    }

    /**
     * TASK-214 — the ruling in one test: a leader rate can now name a
     * single product, and a cert tier is no longer required to create one.
     */
    public function test_a_leader_rate_can_be_scoped_to_one_product_without_a_cert_tier(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-override-rules', [
                'product_id' => $product->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 250,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.manager_cert_tier', null);
    }

    /** A rate for a whole category, same scope vocabulary as the agent rate. */
    public function test_a_leader_rate_can_be_scoped_to_a_category(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $category = ProductCategory::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-override-rules', [
                'product_category_id' => $category->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 150,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.product_category.id', $category->id);
    }

    /** Product and category are alternatives, never a pair — same as the agent rate. */
    public function test_a_leader_rate_cannot_name_both_a_product_and_a_category(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $category = ProductCategory::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-override-rules', [
                'product_id' => $product->id,
                'product_category_id' => $category->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 150,
                'effective_from' => now()->toDateString(),
            ])
            ->assertUnprocessable();
    }

    /**
     * TASK-214 — the overlap key MOVED. Two rows that differ only by cert
     * tier used to be legal; they are the exact shape that becomes
     * ambiguous once resolution stops reading the tier, so the guard must
     * now refuse them.
     */
    public function test_two_company_wide_rates_differing_only_by_cert_tier_are_now_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tierA = CertTier::factory()->create(['key' => 'tier_a', 'sort_order' => 2]);
        $tierB = CertTier::factory()->create(['key' => 'tier_b', 'sort_order' => 3]);

        $payload = fn (int $tierId, int $rate) => [
            'manager_cert_tier_id' => $tierId,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => $rate,
            'effective_from' => now()->toDateString(),
        ];

        $this->actingAs($admin)->postJson('/api/v1/commission-override-rules', $payload($tierA->id, 100))->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/commission-override-rules', $payload($tierB->id, 50))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_from');
    }

    /** Different scopes never collide, even on identical dates. */
    public function test_a_product_scoped_rate_does_not_collide_with_the_company_default(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/commission-override-rules', [
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 100,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/commission-override-rules', [
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 250,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();
    }

    /** BR-6 — a Company Admin cannot scope a rate to another company's product. */
    public function test_a_leader_rate_cannot_name_another_companys_product(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $foreignProduct = Product::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-override-rules', [
                'product_id' => $foreignProduct->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 250,
                'effective_from' => now()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
    }

    public function test_company_admin_cannot_update_another_companys_override_rule(): void
    {
        // BR-6 — same cross-tenant guard shape as every other Policy.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $rule = CommissionOverrideRule::factory()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminB)
            ->putJson("/api/v1/commission-override-rules/{$rule->id}", ['rate_value' => 999])
            ->assertNotFound();
    }
}
