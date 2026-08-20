<?php

namespace Database\Factories;

use App\Models\CatalogBrand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogBrand>
 */
class CatalogBrandFactory extends Factory
{
    protected $model = CatalogBrand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'is_active' => true,
        ];
    }
}
