<?php

namespace Database\Factories;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 *
 * ADR-009 — Module is now a Section (pure grouping/ordering container),
 * content-item fields moved to ModuleLessonFactory.
 */
class ModuleFactory extends Factory
{
    protected $model = Module::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'cert_tier_id' => CertTier::factory(),
            'product_id' => null,
            'title' => fake()->unique()->sentence(3),
            'sort_order' => 0,
            'is_published' => true,
            // ADR-031 §2.2/§2.3 — OFF by default, matching the column
            // defaults and every Section that exists in production. The
            // "untouched defaults" regression test depends on a factory
            // Section behaving exactly like a pre-ADR-031 one.
            'enforce_sequential' => false,
            'drip_days' => null,
        ];
    }
}
