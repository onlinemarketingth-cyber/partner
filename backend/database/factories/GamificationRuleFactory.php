<?php

namespace Database\Factories;

use App\Enums\GamificationSourceType;
use App\Models\GamificationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GamificationRule>
 */
class GamificationRuleFactory extends Factory
{
    protected $model = GamificationRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // company_id null by default (platform-wide default) —
            // tests that need a company-specific override pass
            // ->state(['company_id' => $company->id]) explicitly.
            'company_id' => null,
            'source_type' => $this->faker->randomElement(GamificationSourceType::cases()),
            'xp_value' => $this->faker->numberBetween(5, 100),
            'is_active' => true,
        ];
    }
}
