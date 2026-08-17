<?php

namespace Database\Factories;

use App\Models\ClientCategory;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientCategory>
 */
class ClientCategoryFactory extends Factory
{
    protected $model = ClientCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->word(),
            'sort_order' => 0,
        ];
    }
}
