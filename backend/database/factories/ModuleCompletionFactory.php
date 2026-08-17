<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModuleCompletion>
 *
 * Added in Phase 10 (was previously untested via factory — every prior
 * Academy test drove completion through ModuleCompletionService/the API
 * instead). Needed here so BadgeConditionEvaluatorTest can set up
 * modules_completed_count fixtures directly without the full Academy
 * cert-gate scaffolding.
 */
class ModuleCompletionFactory extends Factory
{
    protected $model = ModuleCompletion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'module_lesson_id' => ModuleLesson::factory(),
            'completed_at' => now(),
            'score' => null,
        ];
    }
}
