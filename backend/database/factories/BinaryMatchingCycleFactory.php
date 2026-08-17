<?php

namespace Database\Factories;

use App\Models\BinaryMatchingCycle;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BinaryMatchingCycle>
 */
class BinaryMatchingCycleFactory extends Factory
{
    protected $model = BinaryMatchingCycle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'agent_id' => User::factory()->for($company, 'company'),
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'left_volume_satang' => 0,
            'right_volume_satang' => 0,
            'matched_volume_satang' => 0,
            'unmatched_carried_satang' => 0,
            'commission_ledger_id' => null,
        ];
    }
}
