<?php

namespace Database\Factories;

use App\Enums\ClientActivityType;
use App\Models\Client;
use App\Models\ClientActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientActivity>
 */
class ClientActivityFactory extends Factory
{
    protected $model = ClientActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            // Closure attribute — reads the just-created Client's actual
            // company_id, same pattern as ClientDocumentFactory.
            'company_id' => fn (array $attributes) => Client::find($attributes['client_id'])->company_id,
            'logged_by_user_id' => User::factory()->agent(),
            'type' => fake()->randomElement(ClientActivityType::cases()),
            'summary' => fake()->sentence(),
            'occurred_at' => now(),
            'follow_up_at' => null,
            'follow_up_notified_at' => null,
        ];
    }
}
