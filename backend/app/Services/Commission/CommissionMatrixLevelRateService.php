<?php

namespace App\Services\Commission;

use App\Models\CommissionMatrixLevelRate;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// ADR-011/TASK-030: same "no overlapping date ranges" invariant as
// CommissionOverrideRuleService, keyed by (company_id, level) instead
// of (company_id, manager_cert_tier_id) — see
// commission_matrix_level_rates' own migration comment for why Matrix
// is level-keyed rather than cert-tier-keyed.
class CommissionMatrixLevelRateService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): CommissionMatrixLevelRate
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        $this->assertNoOverlap($data['company_id'], $data['level'], $data['effective_from'], $data['effective_to'] ?? null);

        return CommissionMatrixLevelRate::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommissionMatrixLevelRate $commissionMatrixLevelRate, array $data): CommissionMatrixLevelRate
    {
        $effectiveFrom = $data['effective_from'] ?? $commissionMatrixLevelRate->effective_from;
        $effectiveTo = array_key_exists('effective_to', $data) ? $data['effective_to'] : $commissionMatrixLevelRate->effective_to;

        $this->assertNoOverlap(
            $commissionMatrixLevelRate->company_id,
            $commissionMatrixLevelRate->level,
            $effectiveFrom,
            $effectiveTo,
            excludeId: $commissionMatrixLevelRate->id,
        );

        $commissionMatrixLevelRate->update($data);

        return $commissionMatrixLevelRate;
    }

    private function assertNoOverlap(
        int $companyId,
        int $level,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?int $excludeId = null,
    ): void {
        $overlaps = CommissionMatrixLevelRate::query()
            ->where('company_id', $companyId)
            ->where('level', $level)
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
                'effective_from' => 'This level already has a matrix override rate covering this date range.',
            ]);
        }
    }
}
