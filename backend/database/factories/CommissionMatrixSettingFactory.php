<?php

namespace Database\Factories;

use App\Enums\MatrixSpilloverRule;
use App\Models\Company;
use App\Models\CommissionMatrixSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionMatrixSetting>
 */
class CommissionMatrixSettingFactory extends Factory
{
    protected $model = CommissionMatrixSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'width' => 3,
            'depth' => 5,
            'spillover_rule' => MatrixSpilloverRule::Breadth,
        ];
    }
}
