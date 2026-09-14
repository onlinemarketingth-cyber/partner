<?php

namespace App\Services\Commission;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionRateType;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Scopes\SharedOrTenantScope;
use App\Services\Catalog\ProductPricingService;
use Illuminate\Support\Collection;

/**
 * 2026-09-14 — "สินค้าตัวนี้จ่ายเท่าไหร่ และมาจากชั้นไหน", for every product
 * the company sells, answered by the code that actually pays.
 *
 * ── WHY THIS EXISTS AT ALL ──
 *
 * Owner: "ระบบการทำงานการคิดค่าคอมที่ทับซ้อนกัน 3 ชั้น … ทำได้ไม่ชัดเจน" and,
 * on being offered a read-only explainer versus an editable table, "ข้อเสนอ 2
 * ค่อนข้างเห็นได้ง่ายชัดเจนทำให้แก้ไขได้เลย".
 *
 * The screen is about to show a table that people will use to decide what
 * their agents are paid. There were two ways to fill it:
 *
 *   · recompute the ladder in JavaScript, which the screen ALREADY DOES for
 *     its badges (`resolveRuleFor`), or
 *   · ask the server.
 *
 * The first one is how this system produced its worst bug to date: the
 * browser's copy of the lookup and the ledger's copy disagreed about which
 * company a rate belonged to, a 5% rate set for Thai Life was displayed and
 * PAID on AIA, and the row cannot be corrected (BR-4). A table that is wrong
 * is worse than no table, because a table gets believed. So: the server, via
 * CommissionService's own ladder — the same object, the same queries, the same
 * rounding as a real payout.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ──
 *
 * It does not judge. There is no "state", no red/amber, no blocking_step —
 * CommissionReadinessService already owns the verdict and two services
 * disagreeing about whether a company is ready would be the same mirror
 * problem one level up. This one reports arithmetic: three candidate rates per
 * rung, which one wins, and what it comes to in satang.
 */
class CommissionResolutionService
{
    public function __construct(
        private readonly CommissionService $commissionService,
        private readonly CommissionBasisResolver $commissionBasisResolver,
        private readonly ProductPricingService $productPricingService,
        private readonly OverrideDeductionGuard $deductionGuard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forCompany(Company $company): array
    {
        $companyMode = $this->commissionService->companyOverrideMode($company);
        $depth = $this->deductionGuard->deepestChain($company);
        $rows = [];
        $maxPerLevel = null;

        foreach ($this->sellableProducts($company) as $product) {
            $base = $this->commissionBasisResolver->baseSatang(
                $product,
                $company,
                $this->productPricingService->effectivePriceSatang($product, (int) $company->id),
            );

            $agentLadder = $this->commissionService->commissionRuleLadder($product, (int) $company->id);
            $leaderLadder = $this->commissionService->overrideRuleLadder($product, (int) $company->id);

            $agentAmount = $this->amountFor($agentLadder, $base);
            $leaderRule = $leaderLadder['winner'] === null ? null : $leaderLadder[$leaderLadder['winner']];
            $mode = $this->commissionService->effectiveOverrideMode($leaderRule, $company);

            /*
             * The ceiling is computed HERE, from the same numbers the table
             * shows, rather than by the screen. The guard refuses a rate using
             * this arithmetic at save time; a screen that predicted it with
             * its own copy would eventually offer a maximum the server then
             * rejects, which is the most annoying possible way to be wrong.
             */
            if ($depth > 0 && $agentAmount !== null) {
                $forThisProduct = intdiv($agentAmount, $depth);
                $maxPerLevel = $maxPerLevel === null ? $forThisProduct : min($maxPerLevel, $forThisProduct);
            }

            $rows[] = [
                'product_id' => (int) $product->id,
                'name' => $product->effectiveName(),
                'category' => $product->category_id === null ? null : [
                    'id' => (int) $product->category_id,
                    'name' => $product->category?->name,
                ],
                // The figure every percentage on this row is a percentage OF.
                // Sent so the table can show its own arithmetic rather than
                // asking the reader to trust it.
                'base_satang' => $base,
                'agent' => $this->ladderPayload($agentLadder, $base, $base),
                'leader' => $this->ladderPayload(
                    $leaderLadder,
                    $base,
                    // DeductFromCommission applies the leader's percentage to
                    // the SELLER'S COMMISSION, not to the sale — the two
                    // differ by 33x on the same inputs, so the base has to
                    // follow the mode or the table quietly reports the wrong
                    // one of the two.
                    $mode === CommissionOverrideMode::DeductFromCommission ? ($agentAmount ?? 0) : $base,
                ) + [
                    'override_mode' => $mode->value,
                    // Which layer ANSWERED the mode question, so an inherited
                    // row can say what it is inheriting from instead of
                    // looking like a decision somebody made about it.
                    'override_mode_source' => $leaderRule?->override_mode === null ? 'company' : 'rule',
                ],
            ];
        }

        return [
            'company_id' => (int) $company->id,
            'commission_basis' => $this->commissionBasisResolver->basisFor($company)->value,
            'commission_override_mode' => $companyMode->value,
            'deepest_manager_chain' => $depth,
            /*
             * NULL when there is no chain (nothing can be exhausted) or when
             * no product has an agent rate to divide. Null is "no ceiling
             * applies", not "zero" — rendering 0 would tell an admin they may
             * not set any leader rate at all.
             */
            'max_override_per_level_satang' => $maxPerLevel,
            'products' => $rows,
        ];
    }

    /**
     * One rung's worth of payload, for all three rungs.
     *
     * `null` on a rung means "nothing is set there" — EXCEPT on `category`
     * for a product that has no category, where the ladder itself returns
     * null because the rung does not apply. The caller can tell them apart
     * from the row's own `category` field, and the screen renders them
     * differently: an empty cell you may click versus a dash you may not.
     *
     * @param  array{product: ?CommissionRule|?CommissionOverrideRule, category: mixed, company: mixed, winner: ?string}  $ladder
     * @return array<string, mixed>
     */
    private function ladderPayload(array $ladder, int $displayBaseSatang, int $amountBaseSatang): array
    {
        $rung = fn (string $key) => $ladder[$key] === null ? null : [
            'rule_id' => (int) $ladder[$key]->id,
            'rate_type' => $ladder[$key]->rate_type->value,
            'rate_value' => (int) $ladder[$key]->rate_value,
            /*
             * What this rung WOULD pay if it won — computed for the losers
             * too, on purpose. "5% instead of 8%" is the sentence an admin is
             * actually trying to form when they ask why a rate did not take
             * effect, and a table that printed only percentages would make
             * them do the arithmetic that this system exists to do.
             */
            'amount_satang' => $this->commissionService->computeAmount(
                $ladder[$key]->rate_type,
                (int) $ladder[$key]->rate_value,
                $amountBaseSatang,
            ),
        ];

        return [
            'base_satang' => $displayBaseSatang,
            'amount_base_satang' => $amountBaseSatang,
            'product' => $rung('product'),
            'category' => $rung('category'),
            'company' => $rung('company'),
            'winner' => $ladder['winner'],
            'amount_satang' => $ladder['winner'] === null ? null : $this->commissionService->computeAmount(
                $ladder[$ladder['winner']]->rate_type,
                (int) $ladder[$ladder['winner']]->rate_value,
                $amountBaseSatang,
            ),
        ];
    }

    /**
     * @param  array{winner: ?string}  $ladder
     */
    private function amountFor(array $ladder, int $baseSatang): ?int
    {
        if ($ladder['winner'] === null) {
            return null;
        }

        /** @var CommissionRule $rule */
        $rule = $ladder[$ladder['winner']];

        return $this->commissionService->computeAmount(
            $rule->rate_type instanceof CommissionRateType ? $rule->rate_type : CommissionRateType::from((string) $rule->rate_type),
            (int) $rule->rate_value,
            $baseSatang,
        );
    }

    /**
     * The same set CommissionReadinessService and OverrideDeductionGuard
     * judge — a product this company does not sell has no rate to explain.
     *
     * @return Collection<int, Product>
     */
    private function sellableProducts(Company $company)
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)
            ->with('category')
            ->where(fn ($query) => $query->where('company_id', $company->id)->orWhereNull('company_id'))
            ->get()
            ->filter(fn (Product $product) => $product->isSellableBy((int) $company->id))
            ->sortBy(fn (Product $product) => $product->effectiveName())
            ->values();
    }
}
