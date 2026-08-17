<?php

namespace App\Services\Commission;

use App\Models\CommissionOverrideRule;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// TASK-025 / ADR-006: same "no overlapping date ranges" invariant as
// CommissionRuleService, keyed by (company_id, manager_cert_tier_id)
// instead of (company_id, cert_tier_id, product_id) — there is no
// product dimension for an override rate (ADR-006: keyed by the
// manager's own tier, applies across every product).
class CommissionOverrideRuleService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): CommissionOverrideRule
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        $this->assertNoOverlap($data['company_id'], $data['manager_cert_tier_id'], $data['effective_from'], $data['effective_to'] ?? null);

        return CommissionOverrideRule::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommissionOverrideRule $commissionOverrideRule, array $data): CommissionOverrideRule
    {
        $effectiveFrom = $data['effective_from'] ?? $commissionOverrideRule->effective_from;
        $effectiveTo = array_key_exists('effective_to', $data) ? $data['effective_to'] : $commissionOverrideRule->effective_to;

        $this->assertNoOverlap(
            $commissionOverrideRule->company_id,
            $commissionOverrideRule->manager_cert_tier_id,
            $effectiveFrom,
            $effectiveTo,
            excludeId: $commissionOverrideRule->id,
        );

        $commissionOverrideRule->update($data);

        return $commissionOverrideRule;
    }

    private function assertNoOverlap(
        int $companyId,
        int $managerCertTierId,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?int $excludeId = null,
    ): void {
        $overlaps = CommissionOverrideRule::query()
            ->where('company_id', $companyId)
            ->where('manager_cert_tier_id', $managerCertTierId)
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
                'effective_from' => 'This manager cert tier already has an override rule covering this date range.',
            ]);
        }
    }
}
