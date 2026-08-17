<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'referring_agent_id' => User::factory()->agent()->for($company, 'company'),
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'consent_given_at' => now(),
            'health_notes' => null,
            'status' => ClientStatus::New,
            'lead_source' => null,
        ];
    }
}
