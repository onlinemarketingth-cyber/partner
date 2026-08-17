<?php

namespace Database\Factories;

use App\Models\BinaryLegVolume;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BinaryLegVolume>
 */
class BinaryLegVolumeFactory extends Factory
{
    protected $model = BinaryLegVolume::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'agent_id' => User::factory()->for($company, 'company'),
            'left_volume_satang' => 0,
            'right_volume_satang' => 0,
            'last_cycle_at' => null,
        ];
    }
}
