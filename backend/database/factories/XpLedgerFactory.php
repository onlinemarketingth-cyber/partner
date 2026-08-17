<?php

namespace Database\Factories;

use App\Enums\GamificationSourceType;
use App\Models\User;
use App\Models\XpLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<XpLedger>
 */
class XpLedgerFactory extends Factory
{
    protected $model = XpLedger::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Closure attribute — derive company_id from the just-created
            // User, same idiom as CommissionLedgerFactory/UserBadgeFactory.
            'company_id' => fn (array $attributes) => User::find($attributes['user_id'])->company_id,
            'source_type' => $this->faker->randomElement(GamificationSourceType::cases()),
            'source_id' => $this->faker->numberBetween(1, 1000),
            'xp_awarded' => $this->faker->numberBetween(5, 100),
        ];
    }
}
