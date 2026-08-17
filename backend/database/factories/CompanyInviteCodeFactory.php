<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyInviteCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyInviteCode>
 */
class CompanyInviteCodeFactory extends Factory
{
    protected $model = CompanyInviteCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => Str::upper(Str::random(12)),
            'label' => null,
            // 30 days is a factory-only convenience default, never a
            // production business rule (see TASK-017's design note —
            // a Super Admin always picks the real expiry explicitly).
            'expires_at' => now()->addDays(30),
            'revoked_at' => null,
            'created_by_user_id' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
