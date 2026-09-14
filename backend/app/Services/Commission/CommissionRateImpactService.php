<?php

namespace App\Services\Commission;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionRateType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Scopes\SharedOrTenantScope;
use App\Services\Catalog\ProductPricingService;
use Illuminate\Support\Collection;

/**
 * 2026-09-14 — "บันทึกแล้วจะเกิดอะไรขึ้น", answered BEFORE the save.
 *
 * Owner's ข้อเสนอ 3, chosen alongside the resolution table. The shape is
 * deliberately the one he already approved once: POST /commission-rules/copy
 * with `dry_run: true` previews a copy, and his instruction then was
 * "ต้องกดยืนยัน". This is the same contract for a single rate.
 *
 * ── WHY A PREVIEW AND NOT A WARNING ──
 *
 * The dangerous mistakes on this screen are not invalid input — the form
 * already refuses those. They are VALID rates that do something other than
 * what the admin pictured:
 *
 *   · a category rate that changes four products, two of which they forgot
 *     were in that category;
 *   · a company default that changes nothing at all, because every product
 *     already has its own rate sitting on top of it;
 *   · a rate that reaches nobody, because the category has no sellable
 *     products in it.
 *
 * None of those is an error, so none of them can be caught by validation. The
 * only honest intervention is to show the arithmetic and let a human say yes —
 * and to do it while the ledger is still empty, because afterwards BR-4 means
 * nobody may correct it.
 *
 * ── WHY THE SERVER COMPUTES IT ──
 *
 * Same reason as CommissionResolutionService, and the reason is not
 * theoretical: this screen's JavaScript copy of the resolution ladder once
 * disagreed with the server's and a Thai Life rate was paid on AIA. A preview
 * that disagrees with the save it is previewing is worse than no preview,
 * because it is the thing the admin is trusting at the exact moment they
 * commit.
 */
class CommissionRateImpactService
{
    public function __construct(
        private readonly CommissionService $commissionService,
        private readonly CommissionBasisResolver $commissionBasisResolver,
        private readonly ProductPricingService $productPricingService,
    ) {}

    /**
     * @param  'agent'|'leader'  $kind
     * @param  int|null  $excludeRuleId  the row being EDITED, which must not count as competition against itself
     * @return array<string, mixed>
     */
    public function preview(
        Company $company,
        string $kind,
        CommissionRateType $rateType,
        int $rateValue,
        ?int $productId,
        ?int $productCategoryId,
        ?CommissionOverrideMode $overrideMode = null,
        ?int $excludeRuleId = null,
    ): array {
        $layer = $productId !== null ? 'product' : ($productCategoryId !== null ? 'category' : 'company');
        $changed = [];
        $blocked = [];

        foreach ($this->productsInScope($company, $productId, $productCategoryId) as $product) {
            $base = $this->commissionBasisResolver->baseSatang(
                $product,
                $company,
                $this->productPricingService->effectivePriceSatang($product, (int) $company->id),
            );

            $ladder = $kind === 'agent'
                ? $this->commissionService->commissionRuleLadder($product, (int) $company->id)
                : $this->commissionService->overrideRuleLadder($product, (int) $company->id);

            /*
             * The rate being previewed wins only if nothing NARROWER is in its
             * way — and the row being edited is not in its own way. Without
             * that exclusion, editing a product rate would report itself as
             * the thing blocking itself, which is true of the database and
             * useless to the reader.
             */
            $blocker = $this->blockerFor($layer, $ladder, $excludeRuleId);

            $currentRule = $ladder['winner'] === null ? null : $ladder[$ladder['winner']];
            $before = $currentRule === null ? null : $this->commissionService->computeAmount(
                $currentRule->rate_type,
                (int) $currentRule->rate_value,
                $this->amountBase($kind, $product, $company, $base, $overrideMode),
            );

            if ($blocker !== null) {
                $blocked[] = [
                    'product_id' => (int) $product->id,
                    'name' => $product->effectiveName(),
                    'blocked_by' => $blocker,
                    'amount_satang' => $before,
                ];

                continue;
            }

            $after = $this->commissionService->computeAmount(
                $rateType,
                $rateValue,
                $this->amountBase($kind, $product, $company, $base, $overrideMode),
            );

            $changed[] = [
                'product_id' => (int) $product->id,
                'name' => $product->effectiveName(),
                'before_satang' => $before,
                'after_satang' => $after,
                // Reported rather than inferred by the reader: "no change" is
                // a real and common outcome (re-saving the same number), and a
                // preview that listed it as a change would cry wolf.
                'unchanged' => $before === $after,
            ];
        }

        return [
            'layer' => $layer,
            'changed' => $changed,
            'blocked' => $blocked,
            'changed_count' => count(array_filter($changed, fn ($row) => ! $row['unchanged'])),
            'blocked_count' => count($blocked),
            /*
             * The outcome most worth its own field: a rate that reaches
             * nothing at all. It is not an error — a category may legitimately
             * be configured before its products exist — but it is almost never
             * what the admin meant, and nothing else on the screen would say
             * so.
             */
            'reaches_nothing' => $changed === [] && $blocked === [],
        ];
    }

    /**
     * Which narrower rung, if any, will keep this rate from applying.
     *
     * @param  array{product: mixed, category: mixed, company: mixed, winner: ?string}  $ladder
     */
    private function blockerFor(string $layer, array $ladder, ?int $excludeRuleId): ?string
    {
        $live = fn (string $rung) => $ladder[$rung] !== null && (int) $ladder[$rung]->id !== $excludeRuleId;

        if ($layer === 'product') {
            // Nothing is narrower than a product.
            return null;
        }

        if ($layer === 'category') {
            return $live('product') ? 'product' : null;
        }

        if ($live('product')) {
            return 'product';
        }

        return $live('category') ? 'category' : null;
    }

    /**
     * What the percentage applies to.
     *
     * For the leader under DeductFromCommission that is the SELLER'S
     * COMMISSION, not the sale — the two differ by 33x on the same inputs, and
     * a preview that used the wrong one would be confidently wrong about the
     * exact number the mode selector exists to explain.
     */
    private function amountBase(string $kind, Product $product, Company $company, int $base, ?CommissionOverrideMode $overrideMode): int
    {
        if ($kind === 'agent') {
            return $base;
        }

        $mode = $overrideMode ?? $this->commissionService->companyOverrideMode($company);

        if ($mode !== CommissionOverrideMode::DeductFromCommission) {
            return $base;
        }

        $agentRule = $this->commissionService->resolveCommissionRule($product, (int) $company->id);

        return $agentRule === null ? 0 : $this->commissionService->computeAmount(
            $agentRule->rate_type,
            (int) $agentRule->rate_value,
            $base,
        );
    }

    /**
     * The products this rate can reach — the same relationship
     * OverrideDeductionGuard::productsInScope() describes, read the same way.
     *
     * @return Collection<int, Product>
     */
    private function productsInScope(Company $company, ?int $productId, ?int $productCategoryId)
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)
            ->where(fn ($query) => $query->where('company_id', $company->id)->orWhereNull('company_id'))
            ->when($productId !== null, fn ($query) => $query->where('id', $productId))
            ->when($productCategoryId !== null, fn ($query) => $query->where('category_id', $productCategoryId))
            ->get()
            ->filter(fn (Product $product) => $product->isSellableBy((int) $company->id))
            ->sortBy(fn (Product $product) => $product->effectiveName())
            ->values();
    }
}
