<?php

namespace App\Services\Commission;

use App\Enums\AffiliateOverrideMode;
use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PaymentStatus;
use App\Models\CertTier;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\ProductPricePromotion;
use App\Models\Referral;
use App\Models\User;
use App\Services\Catalog\ProductPricingService;
use Illuminate\Support\Facades\Log;

/**
 * BR-4: "When a commission-triggering condition occurs, record it as an
 * immutable ledger entry." BR-2: rate depends on the agent's cert tier x
 * the package sold, and always comes from commission_rules — never
 * hardcoded here. Section 4.3: the trigger point (Complete Payment) is
 * decided by PipelineService, which calls recordForReferral() — this
 * Service only knows how to compute and write the entry, not when.
 */
class CommissionService
{
    // Section 7 — "no magic numbers": this is a circuit breaker only
    // (guards against a corrupted/cyclic manager_id chain slipping past
    // UserService::assertValidManager()'s own cycle check), not a real
    // business-defined override depth cap — TASK-025 has no real cap.
    private const MAX_OVERRIDE_CHAIN_DEPTH = 100;

    public function __construct(
        private readonly BinaryCommissionService $binaryCommissionService,
        private readonly MatrixCommissionService $matrixCommissionService,
        private readonly StairstepCommissionService $stairstepCommissionService,
        private readonly GenerationCommissionService $generationCommissionService,
        // TASK-136 (risk R1) — the price-promotion lookup that used to
        // live in this class's own resolveActivePricePromotion() moved
        // to ProductPricingService so OrderService charges the customer
        // the SAME number commission is computed from. Behaviour here is
        // unchanged; only the owner of the query moved.
        private readonly ProductPricingService $productPricingService,
        // TASK-174 — the ONE server-side predicate for "is TASK-026's
        // co-agent split enabled for this company" (human decision D2).
        // Injected rather than resolved inline so this Service keeps
        // asking the same question every endpoint and Resource asks.
        private readonly CommissionSplitSettingService $commissionSplitSettingService,
    ) {
    }

    /**
     * Idempotent: if a ledger entry already exists for this referral,
     * returns the existing row instead of creating a second one.
     *
     * CAUTION — this idempotence is APPLICATION-LEVEL ONLY. The docblock
     * used to claim `commission_ledger.referral_id` is uniquely
     * constrained (migration 2026_07_09_200000); migration
     * 2026_07_14_130000 DROPPED that unique index and replaced it with a
     * plain one, because TASK-029's Binary cycles write ledger rows with
     * no referral at all. The check below is therefore a non-atomic
     * check-then-create with no lock: two genuinely CONCURRENT calls for
     * the same referral could both pass it. Every caller today is inside
     * a DB transaction that also moves the referral's stage, which makes
     * a real double-fire unlikely, but nothing at the schema level
     * forbids it. Do not add a caller that relies on the old guarantee —
     * add a lock, or restore a partial unique index, first.
     * (Stale comment found in the TASK-176 review, 2026-08-12.)
     *
     * Returns null (and logs a warning, never
     * throws) if commission cannot currently be computed — a missing
     * commission_rules row is a configuration gap for a human to fix,
     * not something this Service should guess at or that should block
     * the referral's pipeline from advancing.
     */
    public function recordForReferral(Referral $referral): ?CommissionLedger
    {
        $existing = CommissionLedger::where('referral_id', $referral->id)->first();
        if ($existing) {
            return $existing;
        }

        $agent = $referral->agent;
        $tier = $agent?->highestPassedCertTier();

        if (! $tier) {
            Log::warning("CommissionService: no commission recorded for referral {$referral->id} — agent {$referral->agent_id} has no passed cert tier.");

            return null;
        }

        $rule = $this->resolveCommissionRule($referral->product, $tier->id);

        if (! $rule) {
            Log::warning("CommissionService: no commission recorded for referral {$referral->id} — no active commission_rule for product {$referral->product_id} (or its category, or a company-wide default) / cert_tier {$tier->id}. Configure one in Product Catalog (BR-2).");

            return null;
        }

        // TASK-047 — human-confirmed decision: commission is computed off
        // the DISCOUNTED price when a ProductPricePromotion is active at
        // this exact moment (Complete Payment, BR-4's trigger point) —
        // never a promotion that was active earlier at referral-submission
        // time, or later after this fires. $productPriceSatang is a single
        // shared variable that flows into every payout below it (direct
        // sale, Unilevel override, Binary volume credit, Matrix/Stairstep/
        // Generation overrides) — so switching it here makes ALL of those
        // consistently promotion-aware with zero changes needed in the 4
        // other Commission*Service classes (see this method's own
        // resolveActivePricePromotion() docblock for the lookup itself).
        $appliedPromotion = $this->resolveActivePricePromotion($referral->product);
        $productPriceSatang = $appliedPromotion?->discounted_price_satang ?? $referral->product->price_satang;

        $amountSatang = $this->computeAmount($rule->rate_type, $rule->rate_value, $productPriceSatang);

        // ADR-011/TASK-029/030/031 fix: recordOverrides() (Unilevel),
        // Binary's volume-crediting, Matrix's per-level override payout,
        // Stairstep/Breakaway's rank-differential override, Generation's
        // per-generation override, and (TASK-194) Affiliate's team-leader
        // override are SIX DIFFERENT, mutually-exclusive compensation
        // mechanisms — at most one may ever fire per sale, or a company
        // would get double/triple-paid from the same manager_id/matrix
        // data. Before this gate, recordOverrides() ran unconditionally
        // regardless of commission_plan_type — harmless while
        // Binary/Matrix had no working engine, but not once they go live.
        // Gated on the PRODUCT's effective plan type (TASK-027 —
        // Product::effectivePlanType(), not just the company default)
        // since ADR-011 allows a plan type override per product.
        $effectivePlanType = $referral->product->effectivePlanType();

        // TASK-194 §3.2 — Affiliate's deductive mode has to be resolved
        // BEFORE recordDirectSale() writes the agent's own ledger row,
        // because that row's amount itself is reduced by the manager's
        // cut in that mode, and BR-4 forbids editing a row after it's
        // created. Additive mode doesn't change the agent's row at all
        // (it pays the manager on top, same as Unilevel), so it's fully
        // resolved AFTER, alongside the other 5 mechanisms below — see
        // resolveAffiliateOverride()'s docblock for the fail-safe rules
        // (no manager / no passed tier / no matching rule => null, same
        // as Unilevel).
        $affiliateOverride = null;
        $agentDirectAmountSatang = $amountSatang;

        if ($effectivePlanType === CommissionPlanType::Affiliate) {
            $affiliateMode = $referral->product->effectiveAffiliateOverrideMode();
            $affiliateOverride = $this->resolveAffiliateOverride($agent, $affiliateMode, $productPriceSatang, $amountSatang);

            if ($affiliateOverride && $affiliateMode === AffiliateOverrideMode::Deductive) {
                // Round the manager's cut first (already done inside
                // resolveAffiliateOverride(), via computeAmount()), THEN
                // subtract — never round both sides independently, or the
                // two rows can drift a satang off $amountSatang (BR-3).
                $agentDirectAmountSatang = $amountSatang - $affiliateOverride['amount_satang'];
            }
        }

        $ledger = $this->recordDirectSale($referral, $tier, $rule, $agentDirectAmountSatang, $productPriceSatang, $appliedPromotion);

        if ($effectivePlanType === CommissionPlanType::Unilevel) {
            $this->recordOverrides($referral, $agent, $productPriceSatang, $appliedPromotion);
        } elseif ($effectivePlanType === CommissionPlanType::Binary) {
            $this->binaryCommissionService->creditVolume($referral, $agent, $productPriceSatang);
        } elseif ($effectivePlanType === CommissionPlanType::Matrix) {
            $this->matrixCommissionService->payDownlineOverrides($referral, $agent, $productPriceSatang);
        } elseif ($effectivePlanType === CommissionPlanType::StairstepBreakaway) {
            $this->stairstepCommissionService->payDifferentialOverride($referral, $agent, $productPriceSatang);
        } elseif ($effectivePlanType === CommissionPlanType::Generation) {
            $this->generationCommissionService->payGenerationOverrides($referral, $agent, $productPriceSatang);
        } elseif ($effectivePlanType === CommissionPlanType::Affiliate && $affiliateOverride !== null) {
            $this->createOverrideLedgerRow(
                $referral,
                $affiliateOverride['manager'],
                $affiliateOverride['managerTier'],
                $affiliateOverride['rule'],
                $affiliateOverride['amount_satang'],
                $productPriceSatang,
                $appliedPromotion,
                $agent,
            );
        }

        // TASK-024 (ADR-006): fully opt-in — only stamp a renewal
        // schedule when the rule that just fired actually has a renewal
        // rate configured. A referral whose rule never sets one keeps
        // next_renewal_date = null forever, so DispatchDueRenewalCommissions
        // never touches it (zero behavior change for a company that
        // doesn't use this feature).
        if ($rule->renewal_rate_type) {
            $referral->update(['next_renewal_date' => now()->addYear()->toDateString()]);
        }

        return $ledger;
    }

    /**
     * TASK-026 (ADR-006) — the direct-sale row, split into TWO immutable
     * ledger rows (BR-4) when the referral has a co_agent_id, both
     * earned_via = Direct (this is still one sale, just shared) and
     * summing EXACTLY to $amountSatang: co_agent_id's row is
     * split_percentage% of the total (BR-3 integer rounding), and the
     * referring agent's row takes the remainder — so any 1-satang
     * rounding gap always lands on the referring agent, never the
     * co-agent, and the two rows can never sum to more/less than what a
     * non-split sale would have paid out.
     *
     * A referral with no co_agent_id is unaffected — exactly the single
     * row this method always returns as $ledger, same as before TASK-026.
     *
     * TASK-174 (human decision D1, 2026-08-12) — WHEN THE SPLIT IS SWITCHED
     * OFF FOR THIS COMPANY, A REFERRAL THAT ALREADY CARRIES A co_agent_id
     * PRODUCES ONE ROW, THE FULL AMOUNT, TO THE REFERRING AGENT:
     *
     *   > "A split nobody can see in the UI must not move money;
     *   >  'switched off' has to mean off, or the audit problem this task
     *   >  exists to remove simply becomes invisible instead."
     *
     * It takes the SAME branch a no-co-agent sale takes, deliberately — so
     * the one-row path pays exactly what a non-split sale would have paid,
     * with no second rounding step to lose or invent a satang (BR-3).
     *
     * The stored co_agent_id / split_percentage are NOT cleared (spec §3):
     * switching off stops them being READ here, it does not destroy what an
     * agent entered. Rows already written keep their history untouched —
     * this method only ever creates (BR-4).
     */
    private function recordDirectSale(Referral $referral, CertTier $tier, CommissionRule $rule, int $amountSatang, int $productPriceSatang, ?ProductPricePromotion $appliedPromotion): CommissionLedger
    {
        $splitEnabled = $this->commissionSplitSettingService->isEnabledForCompany($referral->company_id);

        if (! $referral->co_agent_id || ! $splitEnabled) {
            return CommissionLedger::create([
                'company_id' => $referral->company_id,
                'agent_id' => $referral->agent_id,
                'referral_id' => $referral->id,
                'cert_tier_id_at_time' => $tier->id,
                'product_id' => $referral->product_id,
                'sale_price_satang_at_time' => $productPriceSatang,
                'applied_price_promotion_id_at_time' => $appliedPromotion?->id,
                'rate_type_applied' => $rule->rate_type,
                'rate_applied' => $rule->rate_value,
                'amount_satang' => $amountSatang,
                'payment_status' => PaymentStatus::Pending,
                'paid_at' => null,
                'earned_via' => CommissionEarnedVia::Direct,
            ]);
        }

        $coAgentShareSatang = (int) round($amountSatang * $referral->split_percentage / 100);
        $referringAgentShareSatang = $amountSatang - $coAgentShareSatang;

        $referringAgentLedger = CommissionLedger::create([
            'company_id' => $referral->company_id,
            'agent_id' => $referral->agent_id,
            'referral_id' => $referral->id,
            'cert_tier_id_at_time' => $tier->id,
            'product_id' => $referral->product_id,
            'sale_price_satang_at_time' => $productPriceSatang,
            'applied_price_promotion_id_at_time' => $appliedPromotion?->id,
            'rate_type_applied' => $rule->rate_type,
            'rate_applied' => $rule->rate_value,
            'amount_satang' => $referringAgentShareSatang,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
            'earned_via' => CommissionEarnedVia::Direct,
        ]);

        CommissionLedger::create([
            'company_id' => $referral->company_id,
            'agent_id' => $referral->co_agent_id,
            'referral_id' => $referral->id,
            'cert_tier_id_at_time' => $tier->id,
            'product_id' => $referral->product_id,
            'sale_price_satang_at_time' => $productPriceSatang,
            'applied_price_promotion_id_at_time' => $appliedPromotion?->id,
            'rate_type_applied' => $rule->rate_type,
            'rate_applied' => $rule->rate_value,
            'amount_satang' => $coAgentShareSatang,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
            'earned_via' => CommissionEarnedVia::Direct,
        ]);

        // recordOverrides() (called right after this) walks the
        // RETURNED ledger's agent (the referring agent) — the co-agent's
        // own manager chain is out of scope for TASK-026 (spec is silent
        // on it). // TODO: CONFIRM (business rule) — should a co-agent's
        // manager also earn an override on the co-agent's split?
        return $referringAgentLedger;
    }

    /**
     * TASK-025 (Unilevel manager override, ADR-006): walks the selling
     * agent's manager_id chain upward with no depth cap (human decision,
     * ADR-006 Round 2/Addendum). For each manager found, looks up
     * commission_override_rules by that MANAGER's OWN current
     * highestPassedCertTier() (not the selling agent's tier — ADR-006
     * decision). A manager with no configured rate for their tier gets
     * no row at all — never a $0 row (matches TASK-025's acceptance
     * criteria). Each override is a new, separate, immutable
     * commission_ledger row (BR-4) — the original direct-sale row
     * created above is never touched.
     *
     * ag-lead judgment call (not explicitly specified in the task spec):
     * the override rate is applied to the same base as the direct
     * commission — the product's price_satang — exactly like
     * commission_rules, not to the downline's commission amount. This
     * mirrors how "override" works in the real insurance hierarchies
     * researched for ADR-006 (a % of the produced premium, paid at
     * every level, not a % of the level below's own commission).
     */
    private function recordOverrides(Referral $referral, User $sellingAgent, int $productPriceSatang, ?ProductPricePromotion $appliedPromotion): void
    {
        $manager = $sellingAgent->manager;
        $depth = 0;

        while ($manager !== null && $depth < self::MAX_OVERRIDE_CHAIN_DEPTH) {
            $managerTier = $manager->highestPassedCertTier();

            if ($managerTier) {
                $overrideRule = $this->findOverrideRule($managerTier->id);

                if ($overrideRule) {
                    $this->createOverrideLedgerRow(
                        $referral,
                        $manager,
                        $managerTier,
                        $overrideRule,
                        $this->computeAmount($overrideRule->rate_type, $overrideRule->rate_value, $productPriceSatang),
                        $productPriceSatang,
                        $appliedPromotion,
                        $sellingAgent,
                    );
                }
            }

            $manager = $manager->manager;
            $depth++;
        }
    }

    /**
     * TASK-194 §3.2 — Affiliate plan's team-leader override. Resolves
     * (but does NOT write) the manager + matching CommissionOverrideRule
     * + the manager's payout, so the caller can decide the agent's own
     * row's final amount (deductive mode) BEFORE that immutable row
     * (BR-4) is created. Same fail-safe as Unilevel's recordOverrides():
     * no manager, manager has no passed cert tier, or no matching rule
     * for that tier all return null — never a $0 row. Unlike
     * recordOverrides(), this only ever looks at ONE level (the selling
     * agent's own manager) — TASK-194 §3.3 is explicit the Affiliate
     * override writes exactly two ledger rows (agent + manager), not a
     * walked chain.
     *
     * @return array{manager: User, managerTier: CertTier, rule: CommissionOverrideRule, amount_satang: int}|null
     */
    private function resolveAffiliateOverride(User $sellingAgent, AffiliateOverrideMode $mode, int $productPriceSatang, int $agentAmountSatang): ?array
    {
        $manager = $sellingAgent->manager;

        if (! $manager) {
            return null;
        }

        $managerTier = $manager->highestPassedCertTier();

        if (! $managerTier) {
            return null;
        }

        $overrideRule = $this->findOverrideRule($managerTier->id);

        if (! $overrideRule) {
            return null;
        }

        // Additive prices the override against the same base Unilevel's
        // override already uses (the product's price) — spec §3.2.
        // Deductive prices it against the agent's OWN commission, since
        // it's carved out of that same pool rather than paid on top of
        // it — also spec §3.2, and the reason this can't just reuse
        // recordOverrides()'s per-manager math unmodified for this mode.
        $baseSatang = $mode === AffiliateOverrideMode::Deductive ? $agentAmountSatang : $productPriceSatang;

        return [
            'manager' => $manager,
            'managerTier' => $managerTier,
            'rule' => $overrideRule,
            'amount_satang' => $this->computeAmount($overrideRule->rate_type, $overrideRule->rate_value, $baseSatang),
        ];
    }

    /**
     * TASK-025's own manager_cert_tier_id lookup, extracted verbatim
     * (same query, same effective_from/effective_to filter, same
     * "most recent effective_from wins" tiebreak) so recordOverrides()
     * (Unilevel) and resolveAffiliateOverride() (TASK-194, Affiliate)
     * share one implementation instead of two copies of this query.
     */
    private function findOverrideRule(int $managerCertTierId): ?CommissionOverrideRule
    {
        return CommissionOverrideRule::where('manager_cert_tier_id', $managerCertTierId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * TASK-025's own ledger-row shape, extracted verbatim so
     * recordOverrides() (Unilevel) and the TASK-194 Affiliate branch
     * write byte-identical rows via one implementation instead of two
     * copies of this CommissionLedger::create() call — same fields, same
     * earned_via/override_source_agent_id semantics either way.
     */
    private function createOverrideLedgerRow(Referral $referral, User $manager, CertTier $managerTier, CommissionOverrideRule $overrideRule, int $amountSatang, int $productPriceSatang, ?ProductPricePromotion $appliedPromotion, User $sourceAgent): CommissionLedger
    {
        return CommissionLedger::create([
            'company_id' => $referral->company_id,
            'agent_id' => $manager->id,
            'referral_id' => $referral->id,
            'cert_tier_id_at_time' => $managerTier->id,
            'product_id' => $referral->product_id,
            'sale_price_satang_at_time' => $productPriceSatang,
            'applied_price_promotion_id_at_time' => $appliedPromotion?->id,
            'rate_type_applied' => $overrideRule->rate_type,
            'rate_applied' => $overrideRule->rate_value,
            'amount_satang' => $amountSatang,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
            'earned_via' => CommissionEarnedVia::Override,
            'override_source_agent_id' => $sourceAgent->id,
        ]);
    }

    /**
     * TASK-047's promotion lookup — MOVED to ProductPricingService by
     * TASK-136 (risk R1) and delegated to from here.
     *
     * The move is the whole point: OrderService used to snapshot the LIST
     * price onto the order while this Service computed commission from
     * the DISCOUNTED one. Once a customer can check out from a public
     * share link (TASK-136), that discrepancy stops being an internal
     * oddity and becomes "advertised 8,000, charged 8,900". Both now read
     * ProductPricingService, so there is exactly one answer to "what does
     * this product cost right now".
     *
     * Kept as a thin private method rather than inlining the delegation
     * at the call site so this class's flow (and its long TASK-047
     * comment at recordForReferral()) reads exactly as it did.
     */
    private function resolveActivePricePromotion(Product $product): ?ProductPricePromotion
    {
        return $this->productPricingService->activePromotion($product);
    }

    /**
     * ADR-011 Section 2 (TASK-028) — most-specific-wins resolution across
     * the 3 scopes a commission_rules row can now have: (1) a row scoped
     * to this exact product, (2) a row scoped to the product's category
     * with no product set, (3) a company-wide default row (both null).
     * Each step still applies the SAME effective_from/effective_to date
     * filter and "most recent effective_from wins" tiebreak the original
     * (product-only) query always used — this widens WHICH row can match,
     * not how a match among several candidates is picked.
     *
     * Public — DispatchDueRenewalCommissions (TASK-024) must resolve a
     * CURRENT rule (for its renewal_rate_type/value) the exact same way
     * recordForReferral() does. Before TASK-028 that command could get
     * away with a bare where('product_id', ...) query because product_id
     * was the only possible scope; now that a rule can live at the
     * category or company-wide level, duplicating a narrower query there
     * would silently stop finding renewal rates for any referral whose
     * original sale was priced via a category/company-default rule.
     */
    public function resolveCommissionRule(Product $product, int $certTierId): ?CommissionRule
    {
        $baseQuery = fn () => CommissionRule::where('cert_tier_id', $certTierId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->orderByDesc('effective_from');

        $rule = $baseQuery()->where('product_id', $product->id)->first();
        if ($rule) {
            return $rule;
        }

        if ($product->category_id) {
            $rule = $baseQuery()->whereNull('product_id')->where('product_category_id', $product->category_id)->first();
            if ($rule) {
                return $rule;
            }
        }

        return $baseQuery()->whereNull('product_id')->whereNull('product_category_id')->first();
    }

    // BR-3: satang stays an integer end to end. Shared by direct-sale,
    // override (TASK-025), renewal (TASK-024, via
    // DispatchDueRenewalCommissions), AND Binary matched-volume
    // (TASK-029, via BinaryCommissionService) rates — same math,
    // different rule source. Public so callers outside this Service can
    // reuse it rather than duplicating BR-3's rounding rule. Delegates
    // to CommissionRateCalculator (ADR-011/TASK-029) — see that class's
    // docblock for why the calculation itself was pulled out of here.
    public function computeAmount(CommissionRateType $rateType, int $rateValue, int $baseSatang): int
    {
        return CommissionRateCalculator::compute($rateType, $rateValue, $baseSatang);
    }
}
