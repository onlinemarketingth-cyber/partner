<?php

namespace Database\Factories;

use App\Enums\PipelineStage;
use App\Models\PipelineStageLog;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStageLog>
 */
class PipelineStageLogFactory extends Factory
{
    protected $model = PipelineStageLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'referral_id' => Referral::factory(),
            'company_id' => fn (array $attributes) => Referral::find($attributes['referral_id'])->company_id,
            'from_stage' => null,
            'to_stage' => PipelineStage::CompleteRegistered,
            'changed_by_user_id' => fn (array $attributes) => Referral::find($attributes['referral_id'])->agent_id,
            'changed_at' => now(),
        ];
    }
}
