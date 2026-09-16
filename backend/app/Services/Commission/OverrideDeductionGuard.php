<?php

namespace App\Services\Commission;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionRateType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use App\Services\Catalog\ProductPricingService;
use Illuminate\Support\Collection;

/**
 * 2026-09-13 — "ห้ามตั้งเรทที่หักเกิน" (owner's decision, same day).
 *
 * ── THE ARITHMETIC THAT MAKES THIS NECESSARY ──
 *
 * Under CommissionOverrideMode::DeductFromSale the leader's share is a
 * percentage of the SALE but is funded out of a pool that is only the seller's
 * commission. Sell 10,000 with a 3% seller rate and a 2% leader rate and the
 * pool is 300 while each manager takes 200 — so the SECOND manager in the
 * chain already empties it and a third would drive the seller negative.
 *
 * Unilevel walks the whole manager chain with no business depth cap
 * (ADR-006 Round 2), so this is not an exotic configuration. It is what
 * happens on the second sale of any company with a normal hierarchy.
 *
 * CommissionService has a runtime cap for the same reason a seatbelt exists in
 * a car with good brakes — the chain can deepen after a rate was approved. But
 * a runtime cap alone means a leader silently receives nothing and finds out at
 * a payout, so the owner asked for the rate to be REFUSED at save time
 * instead. This class is that refusal, and it is also what the screen reads to
 * show the maximum before anybody types.
 *
 * ── WHY IT COMPUTES IN SATANG RATHER THAN COMPARING PERCENTAGES ──
 *
 * "rate x depth <= seller rate" is only true when both rates are percentages
 * of the same base. They need not be: either side can be a fixed satang
 * amount, and under PV the seller's base is the product's PV while a
 * fixed-amount override is just an amount. Comparing the actual money for each
 * product is longer but has no cases; comparing percentages has four, and
 * three of them are wrong.
 *
 * The check runs per SELLABLE product and reports the worst one, because the
 * binding constraint is the cheapest product the rule has to come out of — a
 * rate that is safe on a 29,900 package and ruinous on a 590 one is not safe.
 */
class OverrideDeductionGuard
{
    public function __construct(
        private readonly CommissionService $commissionService,
        private readonly CommissionBasisResolver $commissionBasisResolver,
        private readonly ProductPricingService $productPricingService,
    ) {}

    /**
     * The reason this override rate cannot be saved, or null when it can.
     *
     * A STRING rather than a boolean because a refusal without a number is a
     * dead end: an admin told "too high" and not "the most you can set is 1.5%"
     * has to guess, and guessing at a rate is how they end up back here.
     *
     * $assumingMode asks the same question about a mode the company has NOT
     * adopted yet, which is what makes the mode switch itself checkable: rates
     * approved under Additive were never measured against any pool, so
     * switching to a deduct mode can break every one of them at once. Null
     * means "the mode this company is actually on" — the rate-form case.
     *
     * 2026-09-14 — $productId / $productCategoryId narrow the check to the
     * products this rate can actually reach. Before per-scope rates that
     * distinction did not exist: every rule was company-wide in effect, so
     * measuring against every sellable product was measuring the right set. It
     * is now wrong in the expensive direction — a 5% leader rate scoped to one
     * 29,900 package would have been refused because some 590 add-on, which
     * this rate will never touch, could not fund it. A guard that blocks
     * configurations it was not asked about teaches people to route around it.
     */
    public function refusalFor(
        Company $company,
        CommissionRateType $rateType,
        int $rateValue,
        ?CommissionOverrideMode $assumingMode = null,
        ?int $productId = null,
        ?int $productCategoryId = null,
    ): ?string {
        $mode = $assumingMode ?? $company->commission_override_mode ?? CommissionOverrideMode::Additive;

        if (! $mode->deductsFromSeller()) {
            // The company pays on top. Nothing is being taken out of anything,
            // so there is nothing to exhaust.
            return null;
        }

        $depth = $this->deepestChain($company);

        if ($depth === 0) {
            // Nobody has a manager, so no override will ever be paid and no
            // pool can be emptied. Refusing here would block a company from
            // configuring the rate BEFORE it builds its hierarchy, which is
            // the order people actually work in.
            return null;
        }

        foreach ($this->productsInScope($company, $productId, $productCategoryId) as $product) {
            $sellerCommission = $this->sellerCommissionSatang($product, $company);

            if ($sellerCommission === null) {
                // No agent rate resolves for this product yet. Step 3 is what
                // fixes that, and complaining about it here would be a second
                // voice saying the same thing in the wrong place.
                continue;
            }

            $perManager = $this->perManagerSatang($mode, $rateType, $rateValue, $product, $company, $sellerCommission);

            if ($perManager * $depth <= $sellerCommission) {
                continue;
            }

            $maxPerManager = intdiv($sellerCommission, $depth);

            return sprintf(
                'อัตรานี้หักเกินค่าแนะนำของผู้ขาย — "%s" จ่ายสมาชิก %s แต่สายงานลึกสุด %d ชั้น '
                .'จึงหักได้ไม่เกินชั้นละ %s (ตอนนี้ตั้งไว้ชั้นละ %s) · '
                .'แก้ได้ 3 ทาง: ลดอัตราหัวหน้าทีม, เพิ่มอัตราสมาชิกในขั้นที่ 3, หรือเปลี่ยนเป็น "บริษัทจ่ายเพิ่ม"',
                $product->effectiveName() ?? "#{$product->id}",
                $this->baht($sellerCommission),
                $depth,
                $this->baht($maxPerManager),
                $this->baht($perManager),
            );
        }

        return null;
    }

    /**
     * How many managers a sale can have above it, at worst.
     *
     * Walked in PHP over one query rather than a recursive CTE: a company's
     * user table is small, this runs when somebody presses save, and a CTE
     * that MySQL and SQLite both accept is not worth owning for either.
     *
     * The visited-set is not paranoia — UserService::assertValidManager()
     * prevents cycles on the write path, and CommissionService still carries
     * its own circuit breaker for the same reason. A guard that hangs the
     * request it was meant to protect is worse than the bug.
     */
    public function deepestChain(Company $company): int
    {
        $managerOf = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->pluck('manager_id', 'id')
            ->all();

        $deepest = 0;

        foreach (array_keys($managerOf) as $userId) {
            $depth = 0;
            $seen = [];
            $current = $managerOf[$userId] ?? null;

            while ($current !== null && ! isset($seen[$current]) && $depth < 100) {
                $seen[$current] = true;
                $depth++;
                $current = $managerOf[$current] ?? null;
            }

            $deepest = max($deepest, $depth);
        }

        return $deepest;
    }

    /**
     * The products a rate at this scope can actually be paid on.
     *
     * Mirrors CommissionService::resolveOverrideRule()'s ladder from the other
     * direction: that method asks "which rule applies to this product", this
     * one asks "which products can this rule reach". The two must describe the
     * same relationship or the guard measures a rate against money it will
     * never come out of.
     *
     * Note what this deliberately does NOT do: a COMPANY-wide rule is checked
     * against every sellable product, including ones that also have their own
     * narrower rate and will therefore never resolve to this one. Being
     * slightly strict there is the safe direction — the narrow rule can be
     * deleted tomorrow, and the company-wide rate would then have to fund that
     * product after all.
     *
     * @return Collection<int, Product>
     */
    private function productsInScope(Company $company, ?int $productId, ?int $productCategoryId)
    {
        // The same set CommissionReadinessService judges — a product this
        // company does not sell cannot exhaust anything.
        return Product::withoutGlobalScope(SharedOrTenantScope::class)
            ->where(fn ($query) => $query->where('company_id', $company->id)->orWhereNull('company_id'))
            ->when($productId !== null, fn ($query) => $query->where('id', $productId))
            ->when($productCategoryId !== null, fn ($query) => $query->where('category_id', $productCategoryId))
            ->get()
            ->filter(fn (Product $product) => $product->isSellableBy((int) $company->id))
            ->values();
    }

    /** What the SELLER earns on this product today, or null when no rate resolves. */
    private function sellerCommissionSatang(Product $product, Company $company): ?int
    {
        $rule = $this->commissionService->resolveCommissionRule($product, (int) $company->id);

        if (! $rule) {
            return null;
        }

        return $this->commissionService->computeAmount(
            $rule->rate_type,
            $rule->rate_value,
            $this->commissionBaseSatang($product, $company),
        );
    }

    private function perManagerSatang(
        CommissionOverrideMode $mode,
        CommissionRateType $rateType,
        int $rateValue,
        Product $product,
        Company $company,
        int $sellerCommission,
    ): int {
        // Mirrors CommissionService::overrideAmountFor() exactly. If those two
        // ever disagree, this guard approves rates the calculation cannot
        // honour — which is the one failure that would make it worse than
        // having no guard.
        return $mode === CommissionOverrideMode::DeductFromCommission
            ? $this->commissionService->computeAmount($rateType, $rateValue, $sellerCommission)
            : $this->commissionService->computeAmount($rateType, $rateValue, $this->commissionBaseSatang($product, $company));
    }

    /** The same base the calculation uses — price or PV, promotion included. */
    private function commissionBaseSatang(Product $product, Company $company): int
    {
        return $this->commissionBasisResolver->baseSatang(
            $product,
            $company,
            $this->productPricingService->effectivePriceSatang($product, (int) $company->id),
        );
    }

    private function baht(int $satang): string
    {
        return number_format($satang / 100, 2).' บาท';
    }
}
