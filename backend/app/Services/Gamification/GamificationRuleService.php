<?php

namespace App\Services\Gamification;

use App\Models\GamificationRule;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// BR-5 config management (distinct from GamificationService, which only
// READS this config to award XP — this Service is the CRUD side, used
// by Admin screens).
class GamificationRuleService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): GamificationRule
    {
        $data['company_id'] = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;
        $data['is_active'] = $data['is_active'] ?? true;

        if ($data['is_active']) {
            $this->assertNoOtherActiveRule($data['company_id'], $data['source_type']);
        }

        return GamificationRule::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GamificationRule $gamificationRule, array $data): GamificationRule
    {
        $sourceType = $data['source_type'] ?? $gamificationRule->source_type;
        $isActive = array_key_exists('is_active', $data) ? $data['is_active'] : $gamificationRule->is_active;

        if ($isActive) {
            $this->assertNoOtherActiveRule($gamificationRule->company_id, $sourceType, excludeId: $gamificationRule->id);
        }

        $gamificationRule->update($data);

        return $gamificationRule;
    }

    /**
     * At most one ACTIVE rule per (company_id, source_type) — otherwise
     * GamificationService::resolveXpValue() would have no defined way
     * to pick between two equally-specific active rules for the same
     * event. company_id may legitimately be null (the platform
     * default), which Eloquent's where() already handles correctly as
     * a NULL check.
     */
    private function assertNoOtherActiveRule(?int $companyId, $sourceType, ?int $excludeId = null): void
    {
        $exists = GamificationRule::where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('is_active', true)
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'source_type' => 'An active rule already exists for this company + source type. Deactivate it first.',
            ]);
        }
    }
}
