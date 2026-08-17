<?php

namespace Database\Factories;

use App\Enums\CommissionRateType;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\CommissionRule;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionRule>
 */
class CommissionRuleFactory extends Factory
{
    protected $model = CommissionRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'cert_tier_id' => CertTier::factory(),
            'product_id' => Product::factory()->for($company, 'company'),
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => fake()->numberBetween(100, 1000),
            'effective_from' => now()->startOfYear()->toDateString(),
            'effective_to' => null,
            // TASK-024 — opt-in, null by default (matches the migration's
            // own default) so every existing test creating a plain
            // CommissionRule keeps today's behavior unchanged.
            'renewal_rate_type' => null,
            'renewal_rate_value' => null,
            'renewal_recurs' => false,
        ];
    }

    /** TASK-024 — a rule with a renewal rate configured. */
    public function withRenewal(int $rateValue = 200, bool $recurs = false): static
    {
        return $this->state(fn (array $attributes) => [
            'renewal_rate_type' => CommissionRateType::Percentage,
            'renewal_rate_value' => $rateValue,
            'renewal_recurs' => $recurs,
        ]);
    }
}
