<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\CertTier;
use App\Models\Company;
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

    public function test_company_admin_cannot_update_another_companys_override_rule(): void
    {
        // BR-6 — same cross-tenant guard shape as every other Policy.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $rule = \App\Models\CommissionOverrideRule::factory()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminB)
            ->putJson("/api/v1/commission-override-rules/{$rule->id}", ['rate_value' => 999])
            ->assertNotFound();
    }
}
