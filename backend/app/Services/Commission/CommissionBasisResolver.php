<?php

namespace App\Services\Commission;

use App\Enums\CommissionBasis;
use App\Models\Company;
use App\Models\Product;

/**
 * 2026-09-12 — THE ONE PLACE THAT ANSWERS "a percentage of WHAT".
 *
 * ── WHY ITS OWN CLASS FOR ELEVEN LINES OF LOGIC ──
 *
 * The same reason Product::effectivePlanType() and
 * ProductPricingService::effectivePriceSatang() are each one place: the
 * number this returns lands in an immutable ledger row (BR-4), and a
 * second copy of the fallback rule is a second copy that can drift. Three
 * callers already need this answer and they are in three different
 * layers — CommissionService (writes the money), CommissionReadinessService
 * (warns before the money is wrong), and the admin API (shows an admin
 * what will happen). If any of them re-derived it, the banner could say
 * "ready" about a base the Service does not use.
 *
 * ── THE FALLBACK, STATED ONCE ──
 *
 * Basis 'price'          → the sale price, always. Today's behaviour.
 * Basis 'pv', PV set     → the PV figure. Note what it deliberately does
 *                          NOT do: it ignores the promotion entirely.
 *                          That is the point of PV, not an oversight —
 *                          discounting a product must not quietly cut
 *                          the agent's commission, which is the single
 *                          most common reason a company adopts PV at all.
 * Basis 'pv', PV null    → the sale price, and the readiness banner says
 *                          so on every admin page. See the pv_satang
 *                          migration for why silence or a zero would both
 *                          be worse.
 *
 * PV 0 is honoured as 0 — `??` and not `?:` — because 0 PV is a real
 * decision (a bundled freebie that pays nobody) and treating it as unset
 * would pay full price commission on it.
 *
 * ── FIXED-AMOUNT RATES NEVER TOUCH THIS ──
 *
 * CommissionRateCalculator ignores the base entirely for
 * CommissionRateType::FixedSatang. A fixed 500 baht is 500 baht whether
 * the company runs on price or on PV. The owner asked for "% และ Fix
 * จำนวนเงิน" to keep working as they do, and this is the reason they do:
 * the basis is an input to one of the two formulas, not a replacement
 * for either.
 */
class CommissionBasisResolver
{
    /**
     * The figure a rate is applied to for this sale.
     *
     * @param  Product  $product  the product being sold
     * @param  Company  $company  the company whose sale this is — ADR-040: a
     *                            platform-owned product has no company of its
     *                            own, so the caller always says who is asking
     * @param  int  $salePriceSatang  what the customer is charged, promotion
     *                                already applied (ProductPricingService)
     */
    public function baseSatang(Product $product, Company $company, int $salePriceSatang): int
    {
        if ($this->basisFor($company) !== CommissionBasis::PointValue) {
            return $salePriceSatang;
        }

        return $product->pv_satang ?? $salePriceSatang;
    }

    /**
     * Which basis a row written for this sale should record.
     *
     * Distinct from baseSatang() on purpose: a PV company selling a
     * product with no PV gets the PRICE figure but is still a PV company,
     * and the ledger row has to say which rule was in force — otherwise
     * the missing-PV gap becomes invisible the moment it is paid out, and
     * "why is this row different from its neighbours" has no answer.
     */
    public function basisFor(Company $company): CommissionBasis
    {
        return $company->commission_basis ?? CommissionBasis::Price;
    }

    /**
     * True when this company runs on PV and this product has none — the
     * one state the readiness banner exists to shout about.
     *
     * Lives here rather than in CommissionReadinessService so that the
     * warning and the calculation can never disagree about what "missing"
     * means; the Service asks this question, it does not re-answer it.
     */
    public function isPointValueMissing(Product $product, Company $company): bool
    {
        return $this->basisFor($company) === CommissionBasis::PointValue
            && $product->pv_satang === null;
    }
}
