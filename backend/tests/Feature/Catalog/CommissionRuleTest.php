<?php

namespace Tests\Feature\Catalog;

use App\Enums\CommissionRateType;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_commission_rules(): void
    {
        // BR-2 config is sensitive compensation data — Agent never gets
        // read access to the raw rate table, only their own ledger.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/commission-rules')
            ->assertForbidden();
    }

    public function test_company_admin_can_create_a_commission_rule(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $product = Product::factory()->for($company)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-rules', [
                'cert_tier_id' => $tier->id,
                'product_id' => $product->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 500,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_overlapping_effective_date_range_is_rejected(): void
    {
        // BR-2 — a CommissionService reading this table must always find
        // exactly one applicable rate; overlapping ranges make that
        // ambiguous, so CommissionRuleService blocks it up front.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $product = Product::factory()->for($company)->create();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 500,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ])->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-rules', [
                'cert_tier_id' => $tier->id,
                'product_id' => $product->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 800,
                'effective_from' => '2026-06-01',
                'effective_to' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_from');
    }

    // ADR-011/TASK-028 — category-level and company-wide-default rules.

    public function test_company_admin_can_create_a_category_level_commission_rule(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $category = ProductCategory::factory()->for($company)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-rules', [
                'cert_tier_id' => $tier->id,
                'product_category_id' => $category->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 400,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.product', null)
            ->assertJsonPath('data.product_category.id', $category->id);
    }

    public function test_company_admin_can_create_a_company_wide_default_commission_rule(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();

        // Neither product_id nor product_category_id set = company-wide
        // default for this cert tier.
        $this->actingAs($admin)
            ->postJson('/api/v1/commission-rules', [
                'cert_tier_id' => $tier->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 200,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.product', null)
            ->assertJsonPath('data.product_category', null);
    }

    public function test_setting_both_product_id_and_product_category_id_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $product = Product::factory()->for($company)->create();
        $category = ProductCategory::factory()->for($company)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-rules', [
                'cert_tier_id' => $tier->id,
                'product_id' => $product->id,
                'product_category_id' => $category->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 400,
                'effective_from' => now()->toDateString(),
            ])
            ->assertUnprocessable();
    }

    // TASK-034 QA gap-fill — Section 5 rule 5 (IDOR): CommissionRulePolicy
    // already scopes view/update/delete by company_id (see the Policy's
    // own comment), but no test previously locked this in for the
    // resource TASK-028 actually extended — every other new commission-
    // plan entity from this sprint (AgentRank, AffiliateLink, etc.) had
    // one; this file didn't. Covers all three scopes (product/category/
    // company-wide) since the Policy check itself doesn't branch on scope.
    public function test_company_admin_cannot_view_update_or_delete_another_companys_commission_rule(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $tier = CertTier::factory()->create();

        $productRule = \App\Models\CommissionRule::factory()->create([
            'company_id' => $companyA->id,
            'cert_tier_id' => $tier->id,
            'product_id' => Product::factory()->for($companyA)->create()->id,
            'product_category_id' => null,
        ]);
        $companyWideRule = \App\Models\CommissionRule::factory()->create([
            'company_id' => $companyA->id,
            'cert_tier_id' => $tier->id,
            'product_id' => null,
            'product_category_id' => null,
        ]);

        // TenantScope's global scope blocks implicit route-model binding
        // from ever resolving company A's row for company B's admin —
        // the request 404s before CommissionRulePolicy is even consulted,
        // same "guard shape" as every other Policy in this family (see
        // AgentRankTest::test_company_admin_cannot_view_another_companys_agent_rank).
        foreach ([$productRule, $companyWideRule] as $rule) {
            $this->actingAs($adminB)->getJson("/api/v1/commission-rules/{$rule->id}")->assertNotFound();
            $this->actingAs($adminB)->putJson("/api/v1/commission-rules/{$rule->id}", ['rate_value' => 999])->assertNotFound();
            $this->actingAs($adminB)->deleteJson("/api/v1/commission-rules/{$rule->id}")->assertNotFound();
        }

        // index() must also never leak company A's rules into company B's list.
        $this->actingAs($adminB)->getJson('/api/v1/commission-rules')->assertOk()->assertJsonMissing(['id' => $productRule->id]);
    }

    public function test_a_category_rule_and_a_company_default_rule_do_not_overlap_each_other(): void
    {
        // Different scopes (category vs company-wide) must be allowed to
        // coexist for the exact same cert tier + date range — only rules
        // in the SAME scope are checked for overlap.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $category = ProductCategory::factory()->for($company)->create();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_category_id' => $category->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 400,
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 200,
            'effective_from' => '2026-01-01',
        ])->assertCreated();
    }
}
