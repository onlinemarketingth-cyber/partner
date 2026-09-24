<?php

namespace App\Services\Commission;

use App\Enums\AffiliateOverrideMode;
use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PaymentStatus;
use App\Models\CertTier;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductPricePromotion;
use App\Models\Referral;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Catalog\ProductPricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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
        // 2026-09-12 (owner: "ทำแผน PV") — the one place that answers
        // "a percentage of WHAT". Injected rather than resolved inline so
        // the readiness banner and this Service cannot disagree about the
        // missing-PV fallback; see that class's docblock.
        private readonly CommissionBasisResolver $commissionBasisResolver,
    ) {}

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
        /*
         * SECURITY AUDIT 2026-08-21 — THE LOCK THE DOCBLOCK ABOVE ASKED FOR.
         *
         * That caution was written when the unique index came off and was
         * never acted on: the check-then-create below stayed non-atomic, so
         * two concurrent confirms of the same referral could both find no
         * existing row and both write one. A double-click on the confirm
         * button, or an HTTP retry after a slow response, is enough — and
         * what it produces is two BR-4 ledger rows that may never be edited
         * or deleted, for one sale.
         *
         * Locking the REFERRAL rather than the ledger, because you cannot
         * lock a row that does not exist yet: the second caller must block
         * on something both callers can see before either has written. The
         * referral is that thing, and it is already the row every caller
         * mutates in the same breath.
         *
         * DB::transaction() rather than assuming one is open: every caller
         * today happens to be inside one, which is exactly the assumption
         * that quietly stops being true. Nested inside an existing
         * transaction this becomes a savepoint and the lock is held to the
         * outer commit, which is the behaviour we want; called bare, it
         * opens the transaction the lock needs to mean anything at all.
         *
         * SQLite compiles lockForUpdate() to nothing, so the test suite
         * proves the logic here, never the locking. The locking is proved
         * by MySQL in production. Do not read a green suite as proof of it.
         */
        return DB::transaction(function () use ($referral) {
            Referral::withoutGlobalScopes()
                ->whereKey($referral->getKey())
                ->lockForUpdate()
                ->first();

            return $this->recordForReferralLocked($referral);
        });
    }

    /** The original body, now only ever reached with the referral row locked. */
    private function recordForReferralLocked(Referral $referral): ?CommissionLedger
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

        // ADR-035: $tier is still fetched above purely as a BR-1 safety
        // net (an agent with no passed cert tier shouldn't be able to
        // reach this point at all — ReferralService::submit() already
        // gates on it, this is defense-in-depth) — it no longer feeds
        // resolveCommissionRule(), which is flat-rate by scope only now.
        $rule = $this->resolveCommissionRule($referral->product, (int) $referral->company_id);

        if (! $rule) {
            Log::warning("CommissionService: no commission recorded for referral {$referral->id} — no active commission_rule for product {$referral->product_id} (or its category, or a company-wide default). Configure one in Product Catalog (BR-2).");

            return null;
        }

        // TASK-047 — human-confirmed decision: commission is computed off
        // the DISCOUNTED price when a ProductPricePromotion is active at
        // this exact moment (Complete Payment, BR-4's trigger point) —
        // never a promotion that was active earlier at referral-submission
        // time, or later after this fires. $commissionBaseSatang (below) is
        // a single shared variable that flows into every payout (direct
        // sale, Unilevel override, Binary volume credit, Matrix/Stairstep/
        // Generation overrides) — so switching it here makes ALL of those
        // consistently promotion-aware with zero changes needed in the 4
        // other Commission*Service classes (see this method's own
        // resolveActivePricePromotion() docblock for the lookup itself).
        // 2026-09-12 — that variable was renamed from $productPriceSatang
        // when PV split it in two; a PV company's promotion reaches the
        // customer's price and stops there, on purpose.
        $appliedPromotion = $this->resolveActivePricePromotion($referral->product, (int) $referral->company_id);
        /*
         * TASK-254 / ADR-040 — the base a commission is computed from is what
         * THIS company charges: its own price for a shared product, the
         * product's own for its own. Reading `product->price_satang` directly
         * would pay commission on the platform's central price while the
         * customer paid the company's — a wrong amount in an immutable ledger
         * row (BR-3/BR-4), and one nobody would notice until a payout.
         */
        $salePriceSatang = $appliedPromotion?->discounted_price_satang
            ?? $this->productPricingService->listPriceSatang($referral->product, (int) $referral->company_id);

        /*
         * 2026-09-12 (owner: "ทำแผน PV กับการตั้งค่าแบบคอม ขายตรง") — TWO
         * NUMBERS FROM HERE DOWN, AND THEY ARE NOT INTERCHANGEABLE.
         *
         *   $salePriceSatang        what the customer paid. Snapshot only.
         *   $commissionBaseSatang   what a rate is applied to.
         *
         * On a 'price' company they are the same integer and every amount
         * below is byte-identical to what this method computed before PV
         * existed — that equality is what makes this change safe to deploy
         * to live companies without touching their data.
         *
         * On a 'pv' company they diverge, and the reason each variable goes
         * where it goes matters: sale price is what an agent's screen and
         * every historical report mean by "the sale", so it keeps its
         * column; PV is what the plan actually pays on, so it is what
         * reaches CommissionRateCalculator and the five downstream engines.
         * Overloading one column to carry both would have started showing
         * points in a field labelled baht (see the ledger migration).
         *
         * Note what the PV branch deliberately IGNORES: $appliedPromotion.
         * A discount moves the price and must not move the commission —
         * that is the whole reason a company adopts PV, not an oversight.
         * The promotion id is still snapshotted on every row, because what
         * the customer paid is still a fact worth keeping.
         */
        $saleValue = new SaleValueSnapshot(
            salePriceSatang: $salePriceSatang,
            basis: $this->commissionBasisResolver->basisFor($referral->company),
            baseSatang: $this->commissionBasisResolver->baseSatang($referral->product, $referral->company, $salePriceSatang),
            appliedPromotion: $appliedPromotion,
        );

        $commissionBaseSatang = $saleValue->baseSatang;

        /*
         * ADR-011/TASK-027 — which plan this SALE runs under. Resolved here
         * rather than below because the seller's own rate now depends on it
         * (ADR-043): only Stairstep reads the rank ladder. It is the same
         * pure product+company lookup either way — nothing between here and
         * its old position could have changed the answer.
         */
        $effectivePlanType = $referral->product->effectivePlanType($referral->company);

        /*
         * ═══ THE SELLER'S OWN RATE (owner choice ค, 2026-09-24 — ADR-043) ═══
         *
         * On Stairstep it is their RANK's rate; everywhere else, and for a
         * Stairstep seller who holds no rank yet, it is the flat
         * commission_rules rate resolved above. See SellerRate for the 13%
         * arithmetic that forced the change and why the fallback exists.
         *
         * $rule is still resolved and still required — the renewal schedule
         * at the bottom of this method reads it, and requiring it keeps the
         * readiness banner's red state meaning exactly what it means today
         * ("a deal closing right now pays nobody"). On Stairstep its rate is
         * simply not what a ranked agent is paid.
         */
        $sellerRate = $this->resolveSellerRate($agent, $effectivePlanType, $rule);

        $amountSatang = $this->computeAmount($sellerRate->rateType, $sellerRate->rateValue, $commissionBaseSatang);

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
        // TASK-253 / ADR-040 — the REFERRAL's company answers for a
        // platform-owned product. Passing it explicitly rather than letting
        // the product look up its own company is what keeps a shared product
        // from silently resolving the wrong plan (BR-2, into an immutable
        // ledger row — BR-4).
        //
        // 2026-09-24 — resolved further up now, because the seller's own rate
        // depends on it (ADR-043).

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
        /*
         * 2026-09-13 — WHERE THE LEADER'S SHARE COMES FROM, and why it is
         * resolved BEFORE the seller's own row is written.
         *
         * Owner: "ปรับได้ทั้งหักจากสมชายปิดการขาย และบริษัทจ่ายเพิ่ม". Two of
         * the three modes take the leader's share OUT of the seller's
         * commission — and BR-4 forbids editing a ledger row once it exists,
         * so the deduction has to be known before recordDirectSale() runs, not
         * after. That is the same constraint TASK-194 already met for
         * Affiliate; Unilevel now meets it too, which is why its overrides are
         * RESOLVED here and only WRITTEN further down.
         */
        $companyOverrideMode = $this->overrideModeFor($referral->company);

        $affiliateOverride = null;
        $unilevelOverrides = [];
        $agentDirectAmountSatang = $amountSatang;

        /*
         * 2026-09-14 — THE MODE IS NOW A PROPERTY OF THE RESOLVED RATE, not of
         * the company alone, so it cannot be known until that rate is known.
         *
         * Hence the rule is resolved HERE rather than inside
         * resolveUnilevelOverrides() where it used to live: the seller's own
         * row depends on the mode (two of the three deduct from it), the
         * seller's row is written before any override row, and BR-4 forbids
         * fixing it afterwards. Resolve the rate, then the mode, then the
         * amounts, then write — in that order, once.
         *
         * `$overrideMode` stays a single value for the whole chain because a
         * single RULE serves the whole chain (see resolveUnilevelOverrides):
         * the rate is a property of the product, identical for every manager
         * above the sale.
         */
        $unilevelOverrideRule = $effectivePlanType === CommissionPlanType::Unilevel
            ? $this->resolveOverrideRule($referral->product, (int) $referral->company_id)
            : null;
        $overrideMode = $this->overrideModeForRule($unilevelOverrideRule, $companyOverrideMode);

        if ($effectivePlanType === CommissionPlanType::Unilevel) {
            $unilevelOverrides = $this->resolveUnilevelOverrides($referral, $agent, $saleValue, $amountSatang, $overrideMode, $unilevelOverrideRule);

            if ($overrideMode->deductsFromSeller()) {
                // Every manager's cut is rounded on its own first (inside
                // resolveUnilevelOverrides, via computeAmount) and only the
                // rounded figures are summed — never round the total, or the
                // seller's row drifts a satang from the sum of the rows it
                // paid for (BR-3).
                $agentDirectAmountSatang = $amountSatang - array_sum(array_column($unilevelOverrides, 'amount_satang'));
            }
        }

        if ($effectivePlanType === CommissionPlanType::Affiliate) {
            $affiliateMode = $this->affiliateOverrideModeFor($referral->product, $referral, $companyOverrideMode);
            $affiliateOverride = $this->resolveAffiliateOverride($agent, $referral->product, (int) $referral->company_id, $affiliateMode, $commissionBaseSatang, $amountSatang);

            if ($affiliateOverride && $affiliateMode->deductsFromSeller()) {
                // Round the manager's cut first (already done inside
                // resolveAffiliateOverride(), via computeAmount()), THEN
                // subtract — never round both sides independently, or the
                // two rows can drift a satang off $amountSatang (BR-3).
                $agentDirectAmountSatang = $amountSatang - $affiliateOverride['amount_satang'];
            }
        }

        $ledger = $this->recordDirectSale($referral, $tier, $sellerRate, $agentDirectAmountSatang, $saleValue);

        /*
         * 2026-09-12 — every engine below takes the COMMISSION BASE, never
         * the sale price. None of them writes sale_price_satang_at_time
         * (their rows are tied to a cycle or a level, not to one priced
         * sale), so the single int each receives is purely "the figure my
         * rate applies to" — which is exactly what PV redefines. A Binary
         * company running on PV therefore credits leg VOLUME in PV too,
         * which is what BV means in every plan this was modelled on, and
         * what makes a promotion unable to shrink a leg.
         */
        if ($effectivePlanType === CommissionPlanType::Unilevel) {
            $this->writeUnilevelOverrides($referral, $agent, $saleValue, $unilevelOverrides, $overrideMode);
        } elseif ($effectivePlanType === CommissionPlanType::Binary) {
            $this->binaryCommissionService->creditVolume($referral, $agent, $commissionBaseSatang);
        } elseif ($effectivePlanType === CommissionPlanType::Matrix) {
            $this->matrixCommissionService->payDownlineOverrides($referral, $agent, $commissionBaseSatang);
        } elseif ($effectivePlanType === CommissionPlanType::StairstepBreakaway) {
            $this->stairstepCommissionService->payDifferentialOverride($referral, $agent, $commissionBaseSatang);
        } elseif ($effectivePlanType === CommissionPlanType::Generation) {
            $this->generationCommissionService->payGenerationOverrides($referral, $agent, $commissionBaseSatang);
        } elseif ($effectivePlanType === CommissionPlanType::Affiliate && $affiliateOverride !== null) {
            $this->createOverrideLedgerRow(
                $referral,
                $affiliateOverride['manager'],
                $affiliateOverride['managerTier'],
                $affiliateOverride['rule'],
                $affiliateOverride['amount_satang'],
                $saleValue,
                $agent,
                $this->affiliateOverrideModeFor($referral->product, $referral, $companyOverrideMode),
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
     * 2026-09-24 (ADR-043) — BOTH SPLIT ROWS CARRY THE REFERRING AGENT'S
     * RATE, including when it came from that agent's rank. A split divides
     * one commission; it does not create a second sale. Reading the
     * co-agent's own rank here would break the invariant this method exists
     * to hold — that the two rows sum EXACTLY to $amountSatang — and it is
     * the same boundary the co-agent's manager chain already sits outside
     * of (see the TODO at the bottom of this method).
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
    private function recordDirectSale(Referral $referral, CertTier $tier, SellerRate $sellerRate, int $amountSatang, SaleValueSnapshot $saleValue): CommissionLedger
    {
        $splitEnabled = $this->commissionSplitSettingService->isEnabledForCompany($referral->company_id);

        if (! $referral->co_agent_id || ! $splitEnabled) {
            return CommissionLedger::create([
                'company_id' => $referral->company_id,
                'agent_id' => $referral->agent_id,
                'referral_id' => $referral->id,
                'cert_tier_id_at_time' => $tier->id,
                'product_id' => $referral->product_id,
                ...$saleValue->ledgerColumns(),
                'rate_type_applied' => $sellerRate->rateType,
                'rate_applied' => $sellerRate->rateValue,
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
            ...$saleValue->ledgerColumns(),
            'rate_type_applied' => $sellerRate->rateType,
            'rate_applied' => $sellerRate->rateValue,
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
            ...$saleValue->ledgerColumns(),
            'rate_type_applied' => $sellerRate->rateType,
            'rate_applied' => $sellerRate->rateValue,
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
     * WHICH LADDER THE SELLER'S OWN RATE IS READ OFF (ADR-043, choice ค).
     *
     * Stairstep is the only plan that measures anything in rank rates —
     * Generation borrows the ladder's breakaway FLAG but prices its own
     * payouts from commission_generation_rules, and the other four never
     * look at agent_ranks at all. So this branches on the plan and leaves
     * five of six behaviours byte-identical to before.
     *
     * The un-ranked fallback is load-bearing rather than defensive: every
     * agent starts with current_rank_id = NULL and only the scheduled
     * recalculation writes it, so without it a recruit's first sale would
     * pay them nothing. It is the same reasoning as
     * StairstepCommissionService's entry-rank substitution (ADR-042 choice
     * 2ก) approached from the other side — there, a missing rank must not
     * OVERPAY the manager; here, it must not UNDERPAY the seller.
     *
     * Deliberately NOT done here: substituting the threshold-0 rank for an
     * un-ranked seller the way the override walk does. The seller has a
     * real rate available — the company's own commission_rules figure —
     * and using a stand-in when the actual answer is in hand would be
     * guessing where guessing is not needed. Keeping the flat rate also
     * means this change cannot alter what any non-Stairstep company, or
     * any Stairstep company that has not built a ladder, is paid.
     */
    private function resolveSellerRate(User $agent, CommissionPlanType $planType, CommissionRule $rule): SellerRate
    {
        if ($planType !== CommissionPlanType::StairstepBreakaway) {
            return SellerRate::fromRule($rule);
        }

        $rank = $agent->currentRank;

        if (! $rank) {
            Log::info("CommissionService: stairstep seller {$agent->id} holds no rank — falling back to the commission_rule rate for their own share (ADR-043).");

            return SellerRate::fromRule($rule);
        }

        return SellerRate::fromRank($rank);
    }

    /**
     * 2026-09-13 — WHO gets a leader override on this sale, and HOW MUCH,
     * resolved WITHOUT writing anything.
     *
     * Split out of the old recordOverrides() because two of the three modes
     * (CommissionOverrideMode) take the leader's share out of the SELLER's
     * commission, and BR-4 forbids editing the seller's row once written. The
     * amounts therefore have to be known before recordDirectSale() runs.
     *
     * Walks the selling agent's manager_id chain upward with no business depth
     * cap (human decision, ADR-006 Round 2/Addendum). For each manager: holding
     * a passed cert tier is what makes them ELIGIBLE (ADR-035 — a gate, never a
     * rate key), and a manager with no matching rule gets no row at all, never
     * a 0 row.
     *
     * ── THE RUNTIME CAP, AND WHY IT EXISTS EVEN THOUGH CONFIG IS GUARDED ──
     *
     * Under DeductFromSale every manager takes a percentage of the SALE out of
     * a pool that is only the seller's commission. At 2% each against a 3%
     * seller rate, two managers already exhaust it and a third would make the
     * seller's row negative. CommissionOverrideRuleService refuses to SAVE a
     * rate that could do that — but it checks against the chain as it is that
     * day, and somebody can be given a manager afterwards.
     *
     * So the pool is tracked here too and the walk stops when it is empty. The
     * managers nearest the seller are paid first, which is the only ordering
     * the walk can offer and also the defensible one: they are the ones who
     * actually supervise the sale. It is logged, loudly, because a leader
     * silently receiving nothing is exactly the kind of thing that surfaces as
     * an accusation weeks later.
     *
     * @return list<array{manager: User, tier: ?CertTier, rule: CommissionOverrideRule, amount_satang: int}>
     */
    private function resolveUnilevelOverrides(
        Referral $referral,
        User $sellingAgent,
        SaleValueSnapshot $saleValue,
        int $agentAmountSatang,
        CommissionOverrideMode $mode,
        ?CommissionOverrideRule $overrideRule,
    ): array {
        // TASK-214 — resolved ONCE, outside the walk: the rate is a property of
        // the PRODUCT, identical for every manager in the chain, so re-querying
        // it per hop would be the same answer at N times the cost.
        //
        // 2026-09-14 — and now resolved one level further out still, by the
        // caller, because $mode is read off this very rule. Passed in rather
        // than re-resolved so the mode and the rate provably come from the
        // same row: two separate lookups could disagree if a rule expires
        // between them, and the disagreement would be a leader paid at a rate
        // whose funding the seller's row was never told about.
        if (! $overrideRule) {
            return [];
        }

        $rows = [];
        $manager = $sellingAgent->manager;
        $depth = 0;
        $poolRemaining = $agentAmountSatang;

        /*
         * 2026-09-19 — HOW FAR UP, AND AT WHAT RATE PER STEP.
         *
         * `max_override_depth` NULL keeps the old behaviour exactly: the walk
         * runs until the chain ends, stopped only by the 100-hop circuit
         * breaker that guards against a corrupted manager_id loop and is not
         * a business rule (see MAX_OVERRIDE_CHAIN_DEPTH).
         *
         * The rate is now resolved PER LEVEL and memoised, because a chain is
         * walked once per sale but a level repeats across sales and the
         * lookup is the same three-rung ladder every time. With no levelled
         * rows configured every level resolves to the same catch-all row the
         * flat walk used, so the amounts are byte-identical.
         *
         * A level is a HOP, counted whether or not that manager was eligible
         * to be paid. Rolling a skipped manager's level onto the next one up
         * is compression — a different feature, with its own switch, and
         * doing it here by accident would change what every existing company
         * pays.
         */
        $maxDepth = $referral->company?->max_override_depth;
        /*
         * 2026-09-19 — COMPRESSION: does a skipped manager spend their level?
         *
         * The certification gate below already skips a manager who passed
         * nothing and walks on. This decides what the NEXT manager is then
         * paid: level 2's rate (compressed) or level 3's (not).
         *
         * False is what the walk has always done, and with one flat rate the
         * difference was invisible — every level paid the same number. Per
         * level rates make it the gap between 3% and 1% for a real person.
         */
        $compress = (bool) ($referral->company?->override_compression ?? false);
        $level = 1;
        $ratePerLevel = [];
        $ruleForLevel = function (int $level) use (&$ratePerLevel, $referral): ?CommissionOverrideRule {
            if (! array_key_exists($level, $ratePerLevel)) {
                /*
                 * No `?? $overrideRule` fallback on purpose. $overrideRule is
                 * whatever the ladder found FIRST, ignoring level — it exists
                 * so the funding mode has one provable source, not so it can
                 * stand in as a rate. Falling back to it would pay level 1's
                 * rate at a level nobody priced, which is the opposite of
                 * what pricing a level means.
                 *
                 * resolveOverrideRuleForLevel() already tries the levelled
                 * row and then the catch-all at each rung, so a null here
                 * means genuinely nothing reaches this level.
                 */
                $ratePerLevel[$level] = $this->resolveOverrideRuleForLevel(
                    $referral->product,
                    (int) $referral->company_id,
                    $level,
                );
            }

            return $ratePerLevel[$level];
        };

        while ($manager !== null && $depth < self::MAX_OVERRIDE_CHAIN_DEPTH) {
            if ($maxDepth !== null && ($compress ? $level : $depth + 1) > $maxDepth) {
                break;
            }

            $managerTier = $manager->highestPassedCertTier();

            /*
             * 2026-09-15 — THE ONE EXEMPTION FROM ADR-035's CERT GATE.
             *
             * A cert tier is a gate on being paid an override: you are
             * eligible because you passed something. The company's own house
             * account sits at the top of the chain (owner: "หัวหน้าทีมที่เป็น
             * ตัวบริษัทเอง") and cannot sit an exam — gating it would mean the
             * company is never paid, whatever anybody configures, and the
             * seller is never charged for it either.
             *
             * The alternative was to write a certification row for the house
             * account and touch no code. That was rejected: it puts a
             * qualification on a money row for an entity that never earned
             * one, and `cert_tier_id_at_time` on the company's ledger rows
             * would read "Basic" forever. The column is nullable; the honest
             * answer is null.
             *
             * NARROW ON PURPOSE — `isCommissionHouseAccount()`, never
             * `isCompanyAdmin()`. A company admin is an ordinary person; only
             * the one row the company points at is exempt.
             */
            $isHouseAccount = $manager->isCommissionHouseAccount();

            if ($managerTier || $isHouseAccount) {
                $levelRule = $ruleForLevel($compress ? $level : $depth + 1);

                if (! $levelRule) {
                    // No rate reaches this level and no catch-all behind it:
                    // nobody is paid here, and the walk continues rather than
                    // stopping, because a level nobody priced is not a reason
                    // to stop paying the levels above it that somebody did.
                    $manager = $manager->manager;
                    $depth++;

                    continue;
                }

                $amount = $this->overrideAmountFor($mode, $levelRule, $saleValue, $agentAmountSatang);

                if ($mode->deductsFromSeller()) {
                    $amount = min($amount, max(0, $poolRemaining));

                    if ($amount <= 0) {
                        Log::warning(
                            "CommissionService: referral {$referral->id} — the seller's commission was exhausted before manager {$manager->id} "
                            ."could be paid a leader override (mode {$mode->value}). The chain is deeper than the configured rate allows; "
                            .'review the leader rate in ขั้นที่ 4.'
                        );

                        break;
                    }

                    $poolRemaining -= $amount;
                }

                $rows[] = [
                    'manager' => $manager,
                    'tier' => $managerTier,
                    'rule' => $levelRule,
                    'amount_satang' => $amount,
                ];

                // Only a PAID hop spends a compressed level. An unpriced
                // level (the `continue` above) does not advance it either —
                // nobody was paid there, so nothing was spent.
                $level++;
            }

            $manager = $manager->manager;
            $depth++;
        }

        return $rows;
    }

    /**
     * The one place the three modes turn into a number.
     *
     * Additive and DeductFromSale pay the SAME amount — a percentage of the
     * sale — and differ only in who funds it, which is why they share a branch
     * here and diverge at the seller's row. DeductFromCommission is the one
     * that changes the BASE, and the 33x difference between it and
     * DeductFromSale on identical inputs is the reason
     * CommissionOverrideMode's cases name their base instead of one of them
     * being called "deductive".
     */
    private function overrideAmountFor(
        CommissionOverrideMode $mode,
        CommissionOverrideRule $rule,
        SaleValueSnapshot $saleValue,
        int $agentAmountSatang,
    ): int {
        return $mode === CommissionOverrideMode::DeductFromCommission
            ? $this->computeAmount($rule->rate_type, $rule->rate_value, $agentAmountSatang)
            : $this->computeAmount($rule->rate_type, $rule->rate_value, $saleValue->baseSatang);
    }

    /**
     * Writes what resolveUnilevelOverrides() decided. Each override is its own
     * immutable ledger row (BR-4); the seller's row, already written by now,
     * is never touched.
     *
     * @param  list<array{manager: User, tier: ?CertTier, rule: CommissionOverrideRule, amount_satang: int}>  $rows
     */
    private function writeUnilevelOverrides(
        Referral $referral,
        User $sellingAgent,
        SaleValueSnapshot $saleValue,
        array $rows,
        CommissionOverrideMode $mode,
    ): void {
        foreach ($rows as $row) {
            $this->createOverrideLedgerRow(
                $referral,
                $row['manager'],
                $row['tier'],
                $row['rule'],
                $row['amount_satang'],
                $saleValue,
                $sellingAgent,
                $mode,
            );
        }
    }

    /**
     * The company's answer, with 'additive' for a company that has never been
     * asked — which is every company that existed before 2026-09-13, and is
     * exactly what Unilevel did unconditionally before that date. Nothing
     * about the deduct modes is opt-out.
     */
    private function overrideModeFor(?Company $company): CommissionOverrideMode
    {
        return $company?->commission_override_mode ?? CommissionOverrideMode::Additive;
    }

    /**
     * 2026-09-14 — THE ONE PLACE the per-rate mode falls back to the company's.
     *
     * `commission_override_rules.override_mode` is nullable and null means
     * "follow the company", which is a third state and the one nearly every
     * row is in. Every reader — the calculation, the save-time guard, the
     * screen — has to coalesce it the same way, so it is coalesced here and
     * nowhere else: a second copy of this line is how a rate ends up deducting
     * on one code path and not on another, with an immutable ledger row to
     * show for it.
     *
     * A null RULE also lands here (no leader rate resolves for this product),
     * and answering with the company's mode is correct and inert: with no rule
     * there is no override to fund, so nothing is deducted from anybody.
     */
    private function overrideModeForRule(?CommissionOverrideRule $rule, CommissionOverrideMode $companyMode): CommissionOverrideMode
    {
        return $rule?->override_mode ?? $companyMode;
    }

    /**
     * Affiliate's mode, across the three places that may answer.
     *
     * PRECEDENCE, most specific first:
     *   1. the resolved leader RATE's own mode (2026-09-14) — somebody set it
     *      on this exact scope, which is as deliberate as an answer gets;
     *   2. TASK-194's per-PRODUCT `affiliate_override_mode` column;
     *   3. the company's setting.
     *
     * The rate beats the product column because the rate is what the admin
     * edits on ขั้นที่ 4 today, while the product column predates that screen
     * and is set from the catalog. When both are set they were set by two
     * different people in two different places, and honouring the more recent,
     * more specific one is the lesser surprise.
     *
     * NULL at every level still resolves to the company's choice, which for a
     * company that has never been asked is Additive — exactly what Affiliate
     * did before any of this existed.
     */
    private function affiliateOverrideModeFor(Product $product, Referral $referral, CommissionOverrideMode $companyMode): CommissionOverrideMode
    {
        $ruleMode = $this->resolveOverrideRule($product, (int) $referral->company_id)?->override_mode;

        if ($ruleMode !== null) {
            return $ruleMode;
        }

        return match ($product->affiliate_override_mode) {
            AffiliateOverrideMode::Deductive => CommissionOverrideMode::DeductFromCommission,
            AffiliateOverrideMode::Additive => CommissionOverrideMode::Additive,
            default => $companyMode,
        };
    }

    /**
     * TASK-194 §3.2 — Affiliate plan's team-leader override. Resolves
     * (but does NOT write) the manager + matching CommissionOverrideRule
     * + the manager's payout, so the caller can decide the agent's own
     * row's final amount (deductive mode) BEFORE that immutable row
     * (BR-4) is created. Same fail-safe as Unilevel's recordOverrides():
     * no manager, an uncertified manager who is not the company's own
     * house account, or no matching rule all return null — never a $0
     * row. Unlike
     * recordOverrides(), this only ever looks at ONE level (the selling
     * agent's own manager) — TASK-194 §3.3 is explicit the Affiliate
     * override writes exactly two ledger rows (agent + manager), not a
     * walked chain.
     *
     * @return array{manager: User, managerTier: ?CertTier, rule: CommissionOverrideRule, amount_satang: int}|null
     */
    private function resolveAffiliateOverride(User $sellingAgent, Product $product, int $companyId, CommissionOverrideMode $mode, int $commissionBaseSatang, int $agentAmountSatang): ?array
    {
        $manager = $sellingAgent->manager;

        if (! $manager) {
            return null;
        }

        $managerTier = $manager->highestPassedCertTier();

        /*
         * 2026-09-23 — THE SAME EXEMPTION UNILEVEL ALREADY HAD, and the
         * reason this plan needed one too.
         *
         * Owner: "ที่ผมอยากได้คือ ผู้แนะนำ = บริษัท ทำให้ setup ได้".
         *
         * Turning on step 4.2 seats the company at the top of the tree and
         * attaches every agent who has no upline to it, so the company IS
         * the ผู้แนะนำ of anyone who joined without one. Under Unilevel that
         * works. Under Affiliate it silently did not: the line below used to
         * read `if (! $managerTier) return null;`, and the seat holds no
         * certification and never will — it cannot sit an exam. So the one
         * case the seat exists for (a seller with no human introducer, whose
         * only upline IS the company) was exactly the case this refused, and
         * it refused it with no error, no zero row and no screen: the card
         * said "บริษัทรับด้วย" and the company was paid nothing, forever.
         *
         * NARROW ON PURPOSE, worded exactly as resolveUnilevelOverrides():
         * `isCommissionHouseAccount()`, never `isCompanyAdmin()`. An ordinary
         * company admin who never certified stays skipped — widening it to a
         * role would start paying real people who never qualified.
         *
         * `$managerTier` is therefore nullable from here on, and the ledger
         * row records NULL rather than a qualification the company never
         * earned (see createOverrideLedgerRow's note on the same column).
         */
        if (! $managerTier && ! $manager->isCommissionHouseAccount()) {
            return null;
        }

        $overrideRule = $this->resolveOverrideRule($product, $companyId);

        if (! $overrideRule) {
            return null;
        }

        // Additive prices the override against the same base Unilevel's
        // override already uses (the product's price) — spec §3.2.
        // Deductive prices it against the agent's OWN commission, since
        // it's carved out of that same pool rather than paid on top of
        // it — also spec §3.2, and the reason this can't just reuse
        // recordOverrides()'s per-manager math unmodified for this mode.
        /*
         * 2026-09-13 — the three modes, via the same helper Unilevel uses, so
         * "what does 2% mean" has one implementation rather than two that can
         * drift. DeductFromSale is new here and is the mode that pays the SAME
         * amount as Additive out of a different pocket — spec §3.2's additive
         * base, funded by the seller.
         */
        $baseSatang = $mode === CommissionOverrideMode::DeductFromCommission ? $agentAmountSatang : $commissionBaseSatang;

        return [
            'manager' => $manager,
            'managerTier' => $managerTier,
            'rule' => $overrideRule,
            'amount_satang' => $this->computeAmount($overrideRule->rate_type, $overrideRule->rate_value, $baseSatang),
        ];
    }

    /**
     * TASK-214 — resolve the team-leader override rate FOR A PRODUCT.
     *
     * Replaces TASK-025's `findOverrideRule(int $managerCertTierId)`. Two
     * human rulings on 2026-08-19 drove this:
     *
     *   1. "ไม่ต้องผูก" — the rate no longer varies by the manager's cert
     *      tier. That was the last place certification acted as a rate
     *      multiplier; ADR-035 had already removed it from the selling
     *      agent's rate for the same reason (passing more exams is not a
     *      reason to earn a higher percentage).
     *   2. "ตามที่คุณเสนอ" — the scope order is IDENTICAL to
     *      resolveCommissionRule()'s: product, then category, then the
     *      company-wide default. Deliberately the same order, and
     *      deliberately the same shape of code, because two different
     *      resolution orders in one system is a hole nobody remembers.
     *
     * The manager still has to HOLD a passed cert tier to be paid at all —
     * that check lives in the two callers and is untouched. It is a gate,
     * not a rate key (ADR-035's own framing of what certification is for).
     *
     * Legacy rows keep a non-null manager_cert_tier_id and both scope
     * columns NULL, so they resolve exactly as they always did: the
     * company-wide default. What changes for them is that several such
     * rows can no longer coexist unambiguously — see
     * commission:collapse-override-tiers.
     */
    private function resolveOverrideRule(Product $product, int $companyId): ?CommissionOverrideRule
    {
        // Same fix, same day, same reasoning as resolveCommissionRule() above —
        // and it had to be the same fix: a leader paid at another company's
        // override rate is the identical defect one level up the chain.
        $baseQuery = $this->liveOverrideRules($companyId);

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

    /**
     * The leader rate for ONE LEVEL of the chain (2026-09-19).
     *
     * Precedence is scope FIRST, level second, and the order matters: at each
     * rung of the existing product > category > company ladder, a row priced
     * for this level wins, and a row with no level (the catch-all, which is
     * every row that existed before levels did) serves the levels nobody has
     * priced. Only when a rung offers neither does the search move up.
     *
     * Putting level outside the ladder instead would silently reverse
     * TASK-214's contract — "a product-specific rate beats the company
     * default" — the first time somebody added a company-wide level-2 rate.
     *
     * Returns null when the rung ladder is exhausted, which is the same
     * "no rate configured, so no row, never a zero row" answer the flat
     * resolver has always given.
     */
    private function resolveOverrideRuleForLevel(Product $product, int $companyId, int $level): ?CommissionOverrideRule
    {
        $baseQuery = $this->liveOverrideRules($companyId);

        $rungs = [
            fn () => $baseQuery()->where('product_id', $product->id),
        ];

        if ($product->category_id) {
            $rungs[] = fn () => $baseQuery()->whereNull('product_id')->where('product_category_id', $product->category_id);
        }

        $rungs[] = fn () => $baseQuery()->whereNull('product_id')->whereNull('product_category_id');

        foreach ($rungs as $rung) {
            $levelled = $rung()->where('level', $level)->first();
            if ($levelled) {
                return $levelled;
            }

            $catchAll = $rung()->whereNull('level')->first();
            if ($catchAll) {
                return $catchAll;
            }
        }

        return null;
    }

    /**
     * The leader-rate ladder, all three rungs — the twin of
     * commissionRuleLadder(). See that method for why both shapes exist.
     *
     * @return array{product: ?CommissionOverrideRule, category: ?CommissionOverrideRule, company: ?CommissionOverrideRule, winner: ?string}
     */
    public function overrideRuleLadder(Product $product, int $companyId): array
    {
        $baseQuery = $this->liveOverrideRules($companyId);

        $atProduct = $baseQuery()->where('product_id', $product->id)->first();
        $atCategory = $product->category_id
            ? $baseQuery()->whereNull('product_id')->where('product_category_id', $product->category_id)->first()
            : null;
        $atCompany = $baseQuery()->whereNull('product_id')->whereNull('product_category_id')->first();

        return [
            'product' => $atProduct,
            'category' => $atCategory,
            'company' => $atCompany,
            'winner' => $atProduct ? 'product' : ($atCategory ? 'category' : ($atCompany ? 'company' : null)),
        ];
    }

    /** @return callable(): Builder<CommissionOverrideRule> */
    private function liveOverrideRules(int $companyId): callable
    {
        return fn () => CommissionOverrideRule::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $companyId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->orderByDesc('effective_from');
    }

    /**
     * PUBLIC as of 2026-09-14 so the resolution screen can ask the same
     * question the payout asks. It was private only because nothing outside
     * had needed it yet; a second copy of this ladder living in a read model
     * is exactly what the Thai-Life-rate-on-AIA bug was made of.
     */
    public function resolveLeaderRule(Product $product, int $companyId): ?CommissionOverrideRule
    {
        return $this->resolveOverrideRule($product, $companyId);
    }

    /** The company-level default, exposed for the same reason as above. */
    public function companyOverrideMode(?Company $company): CommissionOverrideMode
    {
        return $this->overrideModeFor($company);
    }

    /** How the mode for THIS product is settled — rule first, then company. */
    public function effectiveOverrideMode(?CommissionOverrideRule $rule, ?Company $company): CommissionOverrideMode
    {
        return $this->overrideModeForRule($rule, $this->overrideModeFor($company));
    }

    /**
     * TASK-025's own ledger-row shape, extracted verbatim so
     * recordOverrides() (Unilevel) and the TASK-194 Affiliate branch
     * write byte-identical rows via one implementation instead of two
     * copies of this CommissionLedger::create() call — same fields, same
     * earned_via/override_source_agent_id semantics either way.
     */
    private function createOverrideLedgerRow(Referral $referral, User $manager, ?CertTier $managerTier, CommissionOverrideRule $overrideRule, int $amountSatang, SaleValueSnapshot $saleValue, User $sourceAgent, CommissionOverrideMode $mode): CommissionLedger
    {
        return CommissionLedger::create([
            'company_id' => $referral->company_id,
            'agent_id' => $manager->id,
            'referral_id' => $referral->id,
            /*
             * NULL for the company's own house account, which holds no
             * certification and never will (see resolveUnilevelOverrides).
             * The column has been nullable since 2026-07-14; every human
             * payee still carries the tier they qualified under.
             */
            'cert_tier_id_at_time' => $managerTier?->id,
            'product_id' => $referral->product_id,
            ...$saleValue->ledgerColumns(),
            'rate_type_applied' => $overrideRule->rate_type,
            'rate_applied' => $overrideRule->rate_value,
            // 2026-09-13 — BR-4: without this the row cannot explain its own
            // amount. Under DeductFromCommission a 2% rate on a 10,000 sale
            // produces 6, not 200, and the setting that says why is a company
            // toggle somebody may have changed since.
            'override_mode_at_time' => $mode,
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
    private function resolveActivePricePromotion(Product $product, ?int $companyId = null): ?ProductPricePromotion
    {
        // TASK-254 / ADR-040 — a promotion belongs to a company; a shared
        // product has none of its own, so the caller passes the one whose sale
        // this is.
        return $this->productPricingService->activePromotion($product, $companyId);
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
     * ADR-035 — no longer filters by cert_tier_id. Research + human
     * decision (2026-08-18): neither traditional insurance brokerage nor
     * MLM comp plans tie RATE to certification level — cert tier is
     * BR-1's access gate (can this agent sell at all), never a rate
     * multiplier. "Higher commission for better results" is Stairstep/
     * Breakaway's job (agent_ranks, ADR-011 §3c), not Unilevel's. One
     * rate per product/category/company scope now, full stop. If more
     * than one commission_rules row happens to match the same scope for
     * the active date window (legacy data from before this ADR, or a
     * scope that was never cleaned up), "most recent effective_from
     * wins" below still picks one deterministically rather than erroring
     * — CommissionRuleService::assertNoOverlap() is what actually
     * prevents that ambiguity going forward for NEW rows.
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
    public function resolveCommissionRule(Product $product, int $companyId): ?CommissionRule
    {
        /*
         * SECURITY / MONEY FIX 2026-09-12 — THE COMPANY IS NAMED OUT LOUD.
         *
         * This query used to be `CommissionRule::where(...)` and relied on
         * TenantScope to narrow it. That is correct in exactly one situation —
         * a request made by an authenticated Company Admin — and a no-op in
         * the two that matter most:
         *
         *   · a GATEWAY-confirmed payment has no authenticated user at all, so
         *     the scope narrows nothing;
         *   · a SUPER ADMIN is exempt from TenantScope by design (Section 5),
         *     so an admin-confirmed payment is unscoped too.
         *
         * In both, the lookup ran across EVERY company's rules and
         * `orderByDesc('effective_from')` picked whichever happened to sort
         * first. On a platform-owned product — one row that every company
         * sells, ADR-040 — that is not a rare collision, it is the normal
         * case: several companies each hold their own rate for the same
         * product_id.
         *
         * The owner found it from the other end on 2026-09-12: a 5% rate set
         * for Thai Life appeared on AIA's screen. The screen's own bug was
         * cosmetic; this one paid a real agent at a rate nobody at their
         * company had ever set, into a row BR-4 forbids correcting.
         * ExplainCommissionGapCommand has warned about this exact condition
         * since 2026-09-11 ("อัตราที่ระบบหาเจอเป็นของบริษัทอื่น") — the
         * warning was right and nothing had acted on it.
         *
         * withoutGlobalScope(TenantScope::class) + an explicit company_id, so
         * the answer no longer depends on WHO is asking. Named rather than
         * `withoutGlobalScopes()` so that a scope added to this model later
         * (a soft delete, say) is not silently stripped along with it.
         */
        $baseQuery = $this->liveCommissionRules($companyId);

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

    /**
     * 2026-09-14 — THE SAME LADDER, BUT ALL THREE RUNGS AT ONCE.
     *
     * Owner: the admin screen has to show which layer won AND which layers
     * lost, for every product, because "ทำไมตั้งแล้วไม่เปลี่ยน" is almost
     * always "a narrower rate is sitting on top of it".
     *
     * ── WHY THIS IS NOT resolveCommissionRule() REWRITTEN ──
     *
     * resolveCommissionRule() SHORT-CIRCUITS: a product with its own rate
     * costs one query, and it runs on every confirmed sale. Rewriting it in
     * terms of this method would make the money path do three queries to
     * discard two — a real cost paid on the hot path to serve a screen.
     *
     * So the two coexist, and what stops them drifting is that they share
     * liveCommissionRules() (the company scoping, the date window and the
     * ordering — the parts that were actually wrong in the 2026-09-12 bug)
     * plus RateLadderAgreementTest, which asserts row by row that this
     * method's winner IS what resolveCommissionRule() returns. That is the
     * same discipline CommissionReadinessService already carries.
     *
     * @return array{product: ?CommissionRule, category: ?CommissionRule, company: ?CommissionRule, winner: ?string}
     */
    public function commissionRuleLadder(Product $product, int $companyId): array
    {
        $baseQuery = $this->liveCommissionRules($companyId);

        $atProduct = $baseQuery()->where('product_id', $product->id)->first();
        // NULL rather than "not found" when the product has no category at
        // all: the screen has to tell "this rung is empty" from "this rung
        // does not apply to this product", and a product with no category
        // skips the middle of the ladder entirely.
        $atCategory = $product->category_id
            ? $baseQuery()->whereNull('product_id')->where('product_category_id', $product->category_id)->first()
            : null;
        $atCompany = $baseQuery()->whereNull('product_id')->whereNull('product_category_id')->first();

        return [
            'product' => $atProduct,
            'category' => $atCategory,
            'company' => $atCompany,
            'winner' => $atProduct ? 'product' : ($atCategory ? 'category' : ($atCompany ? 'company' : null)),
        ];
    }

    /**
     * The one place the company scope, the date window and the tie-break live
     * for agent rates.
     *
     * Returned as a CLOSURE, not a query: Eloquent builders are stateful, so
     * three rungs off one builder would inherit each other's where clauses —
     * which is a bug that reads as "the category rate mysteriously never
     * matches".
     *
     * @return callable(): Builder<CommissionRule>
     */
    private function liveCommissionRules(int $companyId): callable
    {
        return fn () => CommissionRule::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $companyId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->orderByDesc('effective_from');
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
