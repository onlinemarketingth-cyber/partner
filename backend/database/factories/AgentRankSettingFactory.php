<?php

namespace Database\Factories;

use App\Enums\AgentRankRecalculationFrequency;
use App\Enums\AgentRankVolumeScope;
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
            // The column default, stated explicitly: a factory that left it
            // out would stop exercising the default the moment somebody
            // changed it, and personal-only is the behaviour every existing
            // test in this family was written against.
            'volume_scope' => AgentRankVolumeScope::Personal,
            'recalculation_frequency' => AgentRankRecalculationFrequency::Daily,
            'last_recalculated_at' => null,
        ];
    }
}
