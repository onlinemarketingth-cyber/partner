<?php

namespace App\Services\Commission;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionRateType;
use App\Models\CommissionOverrideRule;
use App\Models\Company;
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
    /*
     * 2026-09-13 — injected so both write paths refuse a rate that would
     * over-deduct. See OverrideDeductionGuard for the arithmetic and for why
     * the refusal happens HERE rather than only at calculation time: a runtime
     * cap leaves a leader silently unpaid, and the owner asked for the rate to
     * be rejected instead ("ห้ามตั้งเรทที่หักเกิน").
     */
    public function __construct(
        private readonly OverrideDeductionGuard $deductionGuard,
    ) {}

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

        $this->assertDeductionFits($companyId, $data);

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

        // The rate can be raised on an EXISTING row too, so the same refusal
        // has to sit on both doors. Falls back to the stored values for the
        // fields this request did not send — an update that only moves a date
        // must be checked against the rate it keeps, not against nothing.
        $this->assertDeductionFits((int) $commissionOverrideRule->company_id, [
            'rate_type' => $data['rate_type'] ?? $commissionOverrideRule->rate_type,
            'rate_value' => $data['rate_value'] ?? $commissionOverrideRule->rate_value,
            // array_key_exists, not ??: null is a MEANING here ("follow the
            // company"), so a request that deliberately clears the rate's own
            // mode must be checked as clearing it, not as keeping the old one.
            'override_mode' => array_key_exists('override_mode', $data) ? $data['override_mode'] : $commissionOverrideRule->override_mode,
            'product_id' => $productId,
            'product_category_id' => $categoryId,
        ]);

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
                'effective_from' => 'ขอบเขตนี้ (สินค้า/หมวดหมู่/ค่าเริ่มต้นทั้งบริษัท) มีอัตราค่าแนะนำหัวหน้าทีมครอบคลุมช่วงเวลานี้อยู่แล้ว',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertDeductionFits(int $companyId, array $data): void
    {
        $company = Company::find($companyId);

        if (! $company) {
            return;
        }

        $rateType = $data['rate_type'] instanceof CommissionRateType
            ? $data['rate_type']
            : CommissionRateType::from((string) $data['rate_type']);

        /*
         * 2026-09-14 — measured against the mode THIS RATE will run under and
         * the products it can actually reach, not against the company's mode
         * and every product.
         *
         * Both halves matter and they pull in opposite directions: a rate that
         * opts INTO a deduct mode at a company sitting on Additive has to be
         * checked (it was not, before), and a rate scoped to one expensive
         * product must not be refused because some cheap add-on it will never
         * touch could not fund it.
         *
         * `?? null` on override_mode keeps the third state intact: absent means
         * "follow the company", which is what the guard's own null default
         * already resolves to.
         */
        $mode = $data['override_mode'] ?? null;
        $mode = $mode instanceof CommissionOverrideMode ? $mode : ($mode === null ? null : CommissionOverrideMode::from((string) $mode));

        $refusal = $this->deductionGuard->refusalFor(
            $company,
            $rateType,
            (int) $data['rate_value'],
            $mode,
            $data['product_id'] ?? null,
            $data['product_category_id'] ?? null,
        );

        if ($refusal !== null) {
            // On `rate_value`, so the admin's cursor lands on the field they
            // have to change — the message names the other two ways out.
            throw ValidationException::withMessages(['rate_value' => $refusal]);
        }
    }
}
