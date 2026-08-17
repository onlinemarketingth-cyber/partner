<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\Company;
use App\Models\CommissionGenerationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-031 — mirrors CommissionMatrixLevelRateTest exactly, keyed
// by generation_number instead of level.
class CommissionGenerationRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_generation_rules(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/commission-generation-rules')
            ->assertForbidden();
    }

    public function test_company_admin_can_create_a_generation_rule(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-generation-rules', [
                'generation_number' => 1,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 500,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.generation_number', 1);
    }

    public function test_overlapping_effective_date_range_for_the_same_generation_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/commission-generation-rules', [
            'generation_number' => 1,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 500,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ])->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-generation-rules', [
                'generation_number' => 1,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 800,
                'effective_from' => '2026-06-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_from');
    }

    public function test_company_admin_cannot_update_another_companys_generation_rule(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $rule = CommissionGenerationRule::factory()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminB)
            ->putJson("/api/v1/commission-generation-rules/{$rule->id}", ['rate_value' => 999])
            ->assertNotFound();
    }
}
