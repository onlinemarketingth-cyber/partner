<?php

namespace Database\Factories;

use App\Enums\AgentApprovalStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'company_id' => Company::factory(),
            'role' => UserRole::Agent,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Super Admin — not scoped to any single company (Section 5, rule 4). */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::SuperAdmin,
            'company_id' => null,
        ]);
    }

    /** Company Admin — manages data within their own company only. */
    public function companyAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::CompanyAdmin,
        ]);
    }

    /** Agent — the default role; explicit alias for readability at call sites. */
    public function agent(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Agent,
        ]);
    }

    /**
     * TASK-112 / ADR-025 §1 — an Agent an Admin has designated a team
     * leader. Composable with agent() (the flag is orthogonal to role by
     * design), e.g. User::factory()->agent()->teamLeader()->create().
     */
    public function teamLeader(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_team_leader' => true,
        ]);
    }

    /** ADR-005 — a self-registered Agent still awaiting Company Admin review. */
    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_approval_status' => AgentApprovalStatus::Pending,
        ]);
    }

    /** ADR-005 — a self-registered Agent whose registration was rejected. */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_approval_status' => AgentApprovalStatus::Rejected,
        ]);
    }
}
