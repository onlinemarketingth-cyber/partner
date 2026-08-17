<?php

namespace Database\Factories;

use App\Enums\CommissionRateType;
use App\Models\CertTier;
use App\Models\CommissionOverrideRule;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionOverrideRule>
 */
class CommissionOverrideRuleFactory extends Factory
{
    protected $model = CommissionOverrideRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'manager_cert_tier_id' => CertTier::factory(),
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => fake()->numberBetween(50, 500),
            'effective_from' => now()->startOfYear()->toDateString(),
            'effective_to' => null,
        ];
    }
}
