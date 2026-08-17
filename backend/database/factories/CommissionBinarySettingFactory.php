<?php

namespace Database\Factories;

use App\Enums\BinaryCycleFrequency;
use App\Enums\CommissionRateType;
use App\Models\Company;
use App\Models\CommissionBinarySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionBinarySetting>
 */
class CommissionBinarySettingFactory extends Factory
{
    protected $model = CommissionBinarySetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'matched_rate_type' => CommissionRateType::Percentage,
            'matched_rate_value' => fake()->numberBetween(500, 1500),
            'cycle_frequency' => BinaryCycleFrequency::Weekly,
            'payout_cap_satang' => null,
            'carry_over_unmatched' => true,
        ];
    }
}
