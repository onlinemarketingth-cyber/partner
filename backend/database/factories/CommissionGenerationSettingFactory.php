<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CommissionGenerationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionGenerationSetting>
 */
class CommissionGenerationSettingFactory extends Factory
{
    protected $model = CommissionGenerationSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'max_generation_depth' => 5,
        ];
    }
}
