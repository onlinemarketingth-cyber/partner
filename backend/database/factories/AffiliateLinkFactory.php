<?php

namespace Database\Factories;

use App\Models\AffiliateLink;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AffiliateLink>
 */
class AffiliateLinkFactory extends Factory
{
    protected $model = AffiliateLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'agent_id' => User::factory()->agent(),
            'product_id' => null,
            'token' => Str::random(64),
        ];
    }
}
