<?php

namespace Database\Factories;

use App\Models\AgentInviteLink;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentInviteLink>
 */
class AgentInviteLinkFactory extends Factory
{
    protected $model = AgentInviteLink::class;

    /**
     * The default state is the "both limits null" link ADR-025 §3 calls
     * unlimited — never expires, no usage cap. That is a real, valid
     * configuration the human explicitly asked for, not a placeholder.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'agent_id' => User::factory()->agent()->teamLeader(),
            'token' => Str::random(64),
            'label' => null,
            'expires_at' => null,
            'max_uses' => null,
            'used_count' => 0,
            'revoked_at' => null,
        ];
    }

    /** Soft-revoked by its owner or an Admin (TASK-113) — isUsable() must be false. */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }

    /** Past its expiry date — isUsable() must be false. */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /** Quota fully consumed (used_count === max_uses) — isUsable() must be false. */
    public function quotaReached(): static
    {
        return $this->state(fn (array $attributes) => [
            'max_uses' => 1,
            'used_count' => 1,
        ]);
    }
}
