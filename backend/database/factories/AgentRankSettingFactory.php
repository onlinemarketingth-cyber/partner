<?php

namespace Database\Factories;

use App\Enums\AgentRankRecalculationFrequency;
use App\Models\AgentRankSetting;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRankSetting>
 */
class AgentRankSettingFactory extends Factory
{
    protected $model = AgentRankSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'trailing_window_days' => 90,
            'recalculation_frequency' => AgentRankRecalculationFrequency::Daily,
            'last_recalculated_at' => null,
        ];
    }
}
