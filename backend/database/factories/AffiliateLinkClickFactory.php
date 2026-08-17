<?php

namespace Database\Factories;

use App\Models\AffiliateLink;
use App\Models\AffiliateLinkClick;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AffiliateLinkClick>
 */
class AffiliateLinkClickFactory extends Factory
{
    protected $model = AffiliateLinkClick::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $link = AffiliateLink::factory()->create();

        return [
            'company_id' => $link->company_id,
            'link_id' => $link->id,
            'clicked_at' => now(),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
