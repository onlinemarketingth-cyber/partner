<?php

namespace Database\Factories;

use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\Product;
use App\Models\Referral;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referral>
 */
class ReferralFactory extends Factory
{
    protected $model = Referral::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            // Closure attributes resolve in array order, with access to
            // already-resolved sibling values — same idiom as
            // ClientDocumentFactory. This derives company_id/agent_id
            // from the just-created Client (rather than an unrelated
            // random Company/User) so tenant/ownership invariants hold
            // by default without every test having to override them.
            'company_id' => fn (array $attributes) => Client::find($attributes['client_id'])->company_id,
            'agent_id' => fn (array $attributes) => Client::find($attributes['client_id'])->referring_agent_id,
            'product_id' => fn (array $attributes) => Product::factory()->create(['company_id' => $attributes['company_id']])->id,
            'branch' => fake()->city(),
            'preferred_time' => now()->addDays(fake()->numberBetween(1, 14)),
            'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null,
            'submitted_at' => now(),
        ];
    }
}
