<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModuleLessonProgress>
 *
 * ADR-028 §2.3. Defaults to a blank row on purpose: every test that cares
 * about the gate should state the exact max_* it is testing, so a future
 * reader can see what the assertion depends on.
 */
class ModuleLessonProgressFactory extends Factory
{
    protected $model = ModuleLessonProgress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'module_lesson_id' => ModuleLesson::factory(),
            'last_position_seconds' => null,
            'max_position_seconds' => null,
            'last_page' => null,
            'max_page' => null,
            'total_pages' => null,
        ];
    }
}
