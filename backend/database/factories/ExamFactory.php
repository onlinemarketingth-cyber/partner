<?php

namespace Database\Factories;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exam>
 */
class ExamFactory extends Factory
{
    protected $model = Exam::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'cert_tier_id' => CertTier::factory(),
            'title' => fake()->unique()->sentence(3),
            'passing_score' => 70,
            'config' => null,
        ];
    }
}
