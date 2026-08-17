<?php

namespace Database\Factories;

use App\Models\AffiliateAttributionSetting;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AffiliateAttributionSetting>
 */
class AffiliateAttributionSettingFactory extends Factory
{
    protected $model = AffiliateAttributionSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'attribution_window_days' => 30,
            'new_vs_returning_rate_differential_enabled' => false,
        ];
    }
}
