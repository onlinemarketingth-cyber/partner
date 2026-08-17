<?php

namespace Database\Factories;

use App\Enums\CommissionRateType;
use App\Models\AgentRank;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRank>
 */
class AgentRankFactory extends Factory
{
    protected $model = AgentRank::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->jobTitle(),
            'volume_threshold' => fake()->numberBetween(0, 1_000_000),
            'sort_order' => 1,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => fake()->numberBetween(100, 1000),
            'is_breakaway_rank' => false,
        ];
    }

    public function breakaway(): static
    {
        return $this->state(['is_breakaway_rank' => true]);
    }
}
