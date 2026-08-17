<?php

namespace Database\Factories;

use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserBadge>
 */
class UserBadgeFactory extends Factory
{
    protected $model = UserBadge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Closure attribute — same idiom as CommissionLedgerFactory —
            // derive company_id from the just-created User so tenant
            // invariants hold by default.
            'company_id' => fn (array $attributes) => User::find($attributes['user_id'])->company_id,
            'badge_id' => Badge::factory(),
            'earned_at' => now(),
        ];
    }
}
