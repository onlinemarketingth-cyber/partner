<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSalesMaterial;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSalesMaterial>
 */
class ProductSalesMaterialFactory extends Factory
{
    protected $model = ProductSalesMaterial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            // Same "derive from already-resolved sibling" idiom as
            // ClientDocumentFactory/ReferralFactory — keeps company_id
            // consistent with the just-created Product by default.
            'company_id' => fn (array $attributes) => Product::find($attributes['product_id'])->company_id,
            'uploaded_by_user_id' => User::factory()->companyAdmin(),
            'file_path' => 'product-materials/test/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'-brochure.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1000, 5000000),
        ];
    }
}
