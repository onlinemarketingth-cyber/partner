<?php

namespace App\Services\Engagement;

use App\Enums\PromotionTargetType;
use App\Models\AgentPromotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Agent-view IA item 1.4. Same company_id-forcing pattern as
// BadgeService::create() — Company Admin is always pinned to their own
// company; only Super Admin may pick another.
class AgentPromotionService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>  $targetAgentIds  Only used when target_type = specific_agents.
     */
    public function create(array $data, User $actor, array $targetAgentIds = []): AgentPromotion
    {
        $data['company_id'] = $actor->isSuperAdmin() ? $data['company_id'] : $actor->company_id;
        $data['created_by'] = $actor->id;

        return DB::transaction(function () use ($data, $targetAgentIds) {
            $promotion = AgentPromotion::create($data);

            if ($promotion->target_type === PromotionTargetType::SpecificAgents) {
                $this->syncTargetAgents($promotion, $targetAgentIds);
            }

            return $promotion->fresh(['targetAgents', 'product', 'targetCertTier']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>|null  $targetAgentIds
     */
    public function update(AgentPromotion $promotion, array $data, ?array $targetAgentIds = null): AgentPromotion
    {
        return DB::transaction(function () use ($promotion, $data, $targetAgentIds) {
            $promotion->update($data);

            $targetType = $promotion->target_type;
            if ($targetType === PromotionTargetType::SpecificAgents && $targetAgentIds !== null) {
                $this->syncTargetAgents($promotion, $targetAgentIds);
            } elseif ($targetType !== PromotionTargetType::SpecificAgents) {
                // Targeting rule changed away from specific_agents — drop
                // stale pivot rows so appliesToAgent() never mixes rules.
                $promotion->targetAgents()->detach();
            }

            return $promotion->fresh(['targetAgents', 'product', 'targetCertTier']);
        });
    }

    public function delete(AgentPromotion $promotion): void
    {
        $promotion->delete();
    }

    /** @param  array<int>  $targetAgentIds */
    private function syncTargetAgents(AgentPromotion $promotion, array $targetAgentIds): void
    {
        if ($targetAgentIds === []) {
            throw ValidationException::withMessages([
                'target_agent_ids' => 'ต้องเลือก Agent อย่างน้อย 1 คน เมื่อกำหนดเป้าหมายแบบเจาะจงราย',
            ]);
        }

        // Defense-in-depth: even though StoreAgentPromotionRequest already
        // validates each id belongs to the actor's own company, re-check
        // here so a Service-level caller (future scheduled job, etc.)
        // can't accidentally attach a cross-tenant agent (BR-6).
        $validIds = User::where('company_id', $promotion->company_id)
            ->whereIn('id', $targetAgentIds)
            ->pluck('id');

        $promotion->targetAgents()->sync($validIds);
    }
}
