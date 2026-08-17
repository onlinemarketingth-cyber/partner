<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductShareLink>
 */
class ProductShareLinkFactory extends Factory
{
    protected $model = ProductShareLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'agent_id' => User::factory()->agent(),
            'product_id' => Product::factory(),
            'token' => Str::random(64),
            'view_count' => 0,
            'revoked_at' => null,
        ];
    }
}
