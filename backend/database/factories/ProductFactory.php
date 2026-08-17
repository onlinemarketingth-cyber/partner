<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'brand_id' => Brand::factory()->for($company, 'company'),
            'category_id' => ProductCategory::factory()->for($company, 'company'),
            'name' => fake()->unique()->words(3, true),
            'price_satang' => fake()->numberBetween(500000, 1500000),
            'is_active' => true,
        ];
    }
}
