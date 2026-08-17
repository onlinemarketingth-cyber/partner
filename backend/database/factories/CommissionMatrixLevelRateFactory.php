<?php

namespace Database\Factories;

use App\Enums\CommissionRateType;
use App\Models\Company;
use App\Models\CommissionMatrixLevelRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionMatrixLevelRate>
 */
class CommissionMatrixLevelRateFactory extends Factory
{
    protected $model = CommissionMatrixLevelRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'level' => 1,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => fake()->numberBetween(100, 1000),
            'effective_from' => now()->startOfYear()->toDateString(),
            'effective_to' => null,
        ];
    }
}
