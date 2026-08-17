<?php

namespace Database\Factories;

use App\Models\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Badge>
 */
class BadgeFactory extends Factory
{
    protected $model = Badge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => null, // platform-wide default by default
            'key' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'icon' => 'trophy',
            'condition_config' => null,
        ];
    }
}
