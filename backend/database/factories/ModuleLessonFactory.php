<?php

namespace Database\Factories;

use App\Enums\ModuleContentType;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModuleLesson>
 *
 * ADR-009 — carries the old flat-Module content-item factory shape
 * (content_type/content_ref/xp_reward), now scoped to a lesson under
 * a Section (Module).
 */
class ModuleLessonFactory extends Factory
{
    protected $model = ModuleLesson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'module_id' => Module::factory(),
            'title' => fake()->unique()->sentence(3),
            'content_type' => ModuleContentType::Video,
            'source_type' => null,
            'content_ref' => fake()->url(),
            'processing_status' => null,
            'sort_order' => 0,
            'xp_reward' => 50,
            'is_published' => true,
            // ADR-031 §2.4 — stated explicitly rather than left to the
            // column default, so a test that means "an optional lesson" has
            // to say so and the default case reads as "required".
            'is_optional' => false,
        ];
    }
}
