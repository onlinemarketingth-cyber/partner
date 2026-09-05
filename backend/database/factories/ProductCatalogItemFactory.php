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
            // TASK-251 — BR-3 satang. A factory item is propagatable by
            // default because that is now the normal state of a catalog item;
            // a test that wants the "no default price" case sets it to null
            // explicitly, which reads as the deliberate choice it is.
            'default_price_satang' => fake()->numberBetween(500000, 1500000),
            'is_active' => true,
        ];
    }
}
