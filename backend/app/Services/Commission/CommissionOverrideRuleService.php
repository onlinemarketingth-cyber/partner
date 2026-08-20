<?php

namespace App\Services\Commission;

use App\Models\CommissionOverrideRule;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// Same "no overlapping date ranges" invariant as CommissionRuleService.
//
// TASK-214 — the key moved from (company_id, manager_cert_tier_id) to
// (company_id, product_id, product_category_id), matching the agent rate
// exactly. That is not a cosmetic change: it is what MAKES two legacy
// per-tier rows a collision. They were legal under the old key and are
// ambiguous under the new one, which is precisely why
// commission:collapse-override-tiers exists and why this guard now
// rejects the shape it used to allow.
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

        $this->assertNoOverlap(
            $data['company_id'],
            $data['product_id'] ?? null,
            $data['product_category_id'] ?? null,
            $data['effective_from'],
            $data['effective_to'] ?? null,
        );

        return CommissionOverrideRule::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommissionOverrideRule $commissionOverrideRule, array $data): CommissionOverrideRule
    {
        $effectiveFrom = $data['effective_from'] ?? $commissionOverrideRule->effective_from;
        $effectiveTo = array_key_exists('effective_to', $data) ? $data['effective_to'] : $commissionOverrideRule->effective_to;

        // Read the INCOMING scope where present, falling back to the
        // stored one — an update that only moves a date must not be
        // checked against the scope it is leaving.
        $productId = array_key_exists('product_id', $data) ? $data['product_id'] : $commissionOverrideRule->product_id;
        $categoryId = array_key_exists('product_category_id', $data) ? $data['product_category_id'] : $commissionOverrideRule->product_category_id;

        $this->assertNoOverlap(
            $commissionOverrideRule->company_id,
            $productId,
            $categoryId,
            $effectiveFrom,
            $effectiveTo,
            excludeId: $commissionOverrideRule->id,
        );

        $commissionOverrideRule->update($data);

        return $commissionOverrideRule;
    }

    private function assertNoOverlap(
        int $companyId,
        ?int $productId,
        ?int $productCategoryId,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?int $excludeId = null,
    ): void {
        $overlaps = CommissionOverrideRule::query()
            ->where('company_id', $companyId)
            // where('col', null) becomes whereNull('col') in Laravel, so
            // product-scoped, category-scoped and company-default rows
            // never collide with each other — same as CommissionRuleService.
            ->where('product_id', $productId)
            ->where('product_category_id', $productCategoryId)
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
                // Thai, like every other message this admin UI renders
                // verbatim. Found in UAT-016 (2026-08-19): the modal put
                // an English sentence in front of a Thai-only admin at the
                // exact moment they needed to understand what went wrong.
                'effective_from' => 'ขอบเขตนี้ (สินค้า/หมวดหมู่/ค่าเริ่มต้นทั้งบริษัท) มีอัตราค่าคอมหัวหน้าทีมครอบคลุมช่วงเวลานี้อยู่แล้ว',
            ]);
        }
    }
}
