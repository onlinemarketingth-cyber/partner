<?php

namespace Database\Factories;

use App\Models\LevelThreshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LevelThreshold>
 */
class LevelThresholdFactory extends Factory
{
    protected $model = LevelThreshold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'level_number' => $this->faker->unique()->numberBetween(1, 100),
            'xp_required' => $this->faker->numberBetween(0, 10000),
        ];
    }
}
