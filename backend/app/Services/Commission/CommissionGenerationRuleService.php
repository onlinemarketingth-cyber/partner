<?php

namespace App\Services\Commission;

use App\Models\CommissionGenerationRule;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// ADR-011/TASK-031: same "no overlapping date ranges" invariant as
// CommissionOverrideRuleService/CommissionMatrixLevelRateService, keyed
// by (company_id, generation_number).
class CommissionGenerationRuleService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): CommissionGenerationRule
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        $this->assertNoOverlap($data['company_id'], $data['generation_number'], $data['effective_from'], $data['effective_to'] ?? null);

        return CommissionGenerationRule::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommissionGenerationRule $commissionGenerationRule, array $data): CommissionGenerationRule
    {
        $effectiveFrom = $data['effective_from'] ?? $commissionGenerationRule->effective_from;
        $effectiveTo = array_key_exists('effective_to', $data) ? $data['effective_to'] : $commissionGenerationRule->effective_to;

        $this->assertNoOverlap(
            $commissionGenerationRule->company_id,
            $commissionGenerationRule->generation_number,
            $effectiveFrom,
            $effectiveTo,
            excludeId: $commissionGenerationRule->id,
        );

        $commissionGenerationRule->update($data);

        return $commissionGenerationRule;
    }

    private function assertNoOverlap(
        int $companyId,
        int $generationNumber,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?int $excludeId = null,
    ): void {
        $overlaps = CommissionGenerationRule::query()
            ->where('company_id', $companyId)
            ->where('generation_number', $generationNumber)
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->where(function ($query) use ($effectiveFrom, $effectiveTo) {
                $query->where('effective_from', '<=', $effectiveTo ?? '9999-12-31')
                    ->where(function ($query) use ($effectiveFrom) {
                        $query->whereNull('effective_to')
                            ->orWhere('effective_to', '>=', $effectiveFrom);
                    });
            })
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'effective_from' => 'This generation number already has a rule covering this date range.',
            ]);
        }
    }
}
