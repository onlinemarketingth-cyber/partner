<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PipelineTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineTemplate>
 */
class PipelineTemplateFactory extends Factory
{
    protected $model = PipelineTemplate::class;

    /**
     * Stages are deliberately NOT created here: a template's stage list
     * is the thing under test in most cases (ADR-026 §3.5 invariants), so
     * each test states its own sequence explicitly rather than inheriting
     * a default that would quietly satisfy the invariants for it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'is_system' => false,
        ];
    }
}
