<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 *
 * TASK-150 / ADR-030 — a library quiz. NOTHING here attaches it to a lesson:
 * the link lives on `module_lessons.quiz_id`, is UNIQUE, and may only move
 * through QuizService::attach() (§2.1). A factory state that set it would be
 * the "seeder walks around the Service" case the ADR explicitly calls out.
 */
class QuizFactory extends Factory
{
    protected $model = Quiz::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'title' => fake()->unique()->sentence(3),
        ];
    }
}
