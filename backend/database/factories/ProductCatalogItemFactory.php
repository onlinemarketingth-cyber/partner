<?php

namespace Database\Factories;

use App\Models\CatalogBrand;
use App\Models\CatalogCategory;
use App\Models\ProductCatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCatalogItem>
 */
class ProductCatalogItemFactory extends Factory
{
    protected $model = ProductCatalogItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_brand_id' => CatalogBrand::factory(),
            'catalog_category_id' => CatalogCategory::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
