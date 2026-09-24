<?php

namespace App\Services\Commission;

use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\UserRole;
use App\Models\AgentRank;
use App\Models\CommissionBinarySetting;
use App\Models\CommissionGenerationRule;
use App\Models\CommissionGenerationSetting;
use App\Models\CommissionMatrixSetting;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 2026-09-11 (owner): "เรื่องค่าคอมเป็นเรื่องสำคัญ หากยังไม่ได้มีการ setup
 * ค่าคอม ให้แจ้งเตือนในทุกหน้า หลังจากมีการตั้งค่าแล้วไม่แสดง หากมีการ setup
 * ค่าคอมไม่ครบถ้วนที่ไม่สมบูรณ์ให้เตือนผู้ใช้"
 *
 * ── WHY THIS EXISTS AT ALL ──
 *
 * A commission rate that was never configured is SILENT BY DESIGN.
 * CommissionService::recordForReferral() logs a warning and returns null
 * rather than blocking the sale (a config gap must never stop the platform
 * recording that a customer paid), so a deal closes, a voucher is issued,
 * an immutable order exists — and nobody is paid, and no screen anywhere
 * says so. The gap surfaces at payout time, weeks later, as an agent asking
 * where their money went. This Service is the answer computed BEFORE that
 * conversation: one small verdict the admin console can put on every page.
 *
 * ── WHY NOT /config-health-report ──
 *
 * That endpoint (BR-7, TASK-041) is a different thing wearing a similar
 * name. It is gated behind Ability::ReportConfigHealthView, it answers per
 * COMPANY rather than per product, and its commission signal is a bare
 * `CommissionRule::count()`. A count cannot tell a live rate from one that
 * expired last month — and an expired-only rate is exactly the case that
 * pays nobody while looking configured. Extending it would have meant
 * making a specialist report the dependency of every admin page load, and
 * coupling "may I see the cross-company report" to "may I be told my own
 * company is not paying anybody".
 *
 * ── THE ONE RULE THAT MUST NEVER DRIFT ──
 *
 * resolveRuleForCompany() below is a HAND-SCOPED MIRROR of
 * CommissionService::resolveCommissionRule(): product rate -> category rate
 * -> company-wide default, first match wins, and a rate only counts while
 * `effective_from <= now` and (`effective_to` is null or `>= now`).
 *
 * IF THOSE TWO EVER DISAGREE, THIS BANNER LIES ABOUT MONEY — it will tell a
 * company it is ready to pay while the Service finds no rule (or the
 * reverse, nagging about a gap that does not exist until the banner is
 * ignored on principle). CommissionReadinessTest::
 * test_it_resolves_every_product_exactly_as_the_commission_service_does
 * asserts the two answers row by row; if you change resolveCommissionRule(),
 * that test is what tells you this file has to change too.
 *
 * There used to be one deliberate difference, and on 2026-09-12 it stopped
 * being a difference at all. This Service named the company explicitly while
 * resolveCommissionRule() leaned on TenantScope — correct where that runs with
 * an authenticated Company Admin, a no-op for a Super Admin (exempt by design)
 * and for a gateway confirmation (no user at all). The owner surfaced the
 * consequence that day: a rate set for one company was being applied to
 * another's sale. resolveCommissionRule() now takes the company as an argument
 * too, so the two are the same query with the same filters, which is what the
 * mirror test below has always claimed they were.
 * See CrossCompanyRateIsolationTest.
 */
class CommissionReadinessService
{
    // 2026-09-12 — see the PV branch in forCompany(): the banner must ask
    // the SAME object the money asks, or "ready" can mean two things.
    // 2026-09-24 — the ladder inspector is SHARED with the write path
    // (AgentRankService / Store+UpdateAgentRankRequest) on purpose. The
    // banner and the save error have to mean the same thing by "this ladder
    // is broken", for the same reason resolveRuleForCompany() is a
    // hand-checked mirror rather than a second opinion.
    public function __construct(
        private readonly CommissionBasisResolver $commissionBasisResolver,
        private readonly AgentRankLadderInspector $ladderInspector,
    ) {}

    /**
     * @return array{
     *     state: string,
     *     blocking_step: int|null,
     *     products_total: int,
     *     products_covered: int,
     *     issues: list<array{code: string, label: string, count: int}>,
     *     can_fix: bool,
     * }
     */
    public function forActor(User $actor, ?int $companyId = null): array
    {
        $companies = $this->companiesInScope($actor, $companyId);

        /*
         * `can_fix` is the SERVER's answer to "may THIS user fix it", and it
         * is deliberately a Policy question rather than a role string handed
         * to the frontend to re-derive. The primary fix for every state this
         * Service can report is authoring a commission_rules row, so
         * CommissionRulePolicy::create() is the honest gate to ask — it is
         * the same one the settings screen's save button sits behind.
         *
         * The owner's 2026-09-11 decision is what makes this field necessary
         * instead of cosmetic: commission config became Super-Admin-only to
         * write, so a Company Admin must be TOLD the state (they run the
         * company; an agent will ask them first) but must never be handed a
         * "go fix it" button that walks them into a 403. If that decision is
         * ever narrowed further or partly reversed, this field follows the
         * Policy automatically and no frontend changes.
         */
        $canFix = $actor->can('create', CommissionRule::class);

        if ($companies->isEmpty()) {
            /*
             * Reachable only when a Super Admin narrows to a company id that
             * does not exist (or was deleted between two requests). There is
             * no company whose commission could be unconfigured, so there is
             * nothing to warn about — 'ready' renders nothing, which is the
             * right amount of noise for a stale query string.
             */
            return $this->payload('ready', null, 0, 0, [], $canFix);
        }

        if ($companies->count() > 1) {
            return $this->aggregate($companies, $canFix);
        }

        return $this->forCompany($companies->first(), $canFix);
    }

    /**
     * BR-6 scoping, the exact shape ConfigHealthReportService::buildReport()
     * uses — a Company Admin is hard-scoped to their own company and a
     * client-supplied company_id is never trusted for that role; a Super
     * Admin may narrow, or see everything.
     *
     * @return Collection<int, Company>
     */
    private function companiesInScope(User $actor, ?int $companyId): Collection
    {
        $query = Company::query()->orderBy('name');

        if (! $actor->isSuperAdmin()) {
            // Company Admin — hard-scoped to their own company (BR-6), never
            // trust a client-supplied company_id for this role.
            $query->where('id', $actor->company_id);
        } elseif ($companyId !== null) {
            $query->where('id', $companyId);
        }

        return $query->get();
    }

    /**
     * A Super Admin looking at "ทุกบริษัท".
     *
     * There is no honest single verdict across companies — "which products
     * are uncovered" has a different answer per tenant, and a banner that
     * named one company's gap while standing in another's context would be
     * worse than silence. So the aggregate reports the WORST state found and
     * points at step 1 ("เลือกบริษัท"), which is genuinely the next action:
     * nothing on the settings screen can be fixed until a company is picked.
     * That is the same thing CommissionPlansView's own step bar says when no
     * company is selected, and the reason `blocking_step` has a 1 at all.
     *
     * @param  Collection<int, Company>  $companies
     * @return array{state: string, blocking_step: int|null, products_total: int, products_covered: int, issues: list<array{code: string, label: string, count: int}>, can_fix: bool}
     */
    private function aggregate(Collection $companies, bool $canFix): array
    {
        $worst = 'ready';
        $notReady = 0;
        $productsTotal = 0;
        $productsCovered = 0;

        foreach ($companies as $company) {
            $one = $this->forCompany($company, $canFix);
            $productsTotal += $one['products_total'];
            $productsCovered += $one['products_covered'];

            if ($one['state'] === 'ready') {
                continue;
            }

            $notReady++;

            // 'missing' outranks 'incomplete' for the same reason it does per
            // product: one company paying NOBODY makes every softer
            // observation about the others irrelevant.
            if ($one['state'] === 'missing') {
                $worst = 'missing';
            } elseif ($worst !== 'missing') {
                $worst = 'incomplete';
            }
        }

        if ($worst === 'ready') {
            return $this->payload('ready', null, $productsTotal, $productsCovered, [], $canFix);
        }

        return $this->payload($worst, 1, $productsTotal, $productsCovered, [[
            'code' => 'companies_not_ready',
            'label' => "บริษัทที่ตั้งค่าแนะนำยังไม่ครบ {$notReady} บริษัท — เลือกบริษัทก่อนจึงจะแก้ได้",
            'count' => $notReady,
        ]], $canFix);
    }

    /**
     * @return array{state: string, blocking_step: int|null, products_total: int, products_covered: int, issues: list<array{code: string, label: string, count: int}>, can_fix: bool}
     */
    private function forCompany(Company $company, bool $canFix): array
    {
        $companyId = (int) $company->id;
        $products = $this->sellableProducts($company);

        /*
         * A COMPANY WITH NOTHING TO SELL IS SILENT.
         *
         * It has plainly not finished setting commission up, and the owner's
         * words were "หากยังไม่ได้มีการ setup ค่าคอม ให้แจ้งเตือน" — so red was
         * the first implementation here. It was wrong for one reason: a
         * platform carries placeholder and wound-down companies with no live
         * product, and a Super Admin on "ทุกบริษัท" would then see the red
         * banner on every page forever, about a company where no deal can
         * close and nobody can be underpaid. A banner that is always on is a
         * banner nobody reads, which is the one failure this feature cannot
         * afford.
         *
         * The warning instead appears the moment it becomes TRUE — the first
         * sellable product with no rate behind it turns the banner red.
         *
         * TODO: CONFIRM (owner) — this trades "warn during onboarding, before
         * there is anything to sell" for "never cry wolf". If onboarding is
         * the case that mattered, the rule to change is this branch only.
         */
        if ($products->isEmpty()) {
            return $this->payload('ready', null, 0, 0, [], $canFix);
        }

        $uncovered = 0;
        $planTypesInUse = [];
        $leaderGaps = 0;
        $pointValueGaps = 0;

        foreach ($products as $product) {
            if ($this->resolveRuleForCompany($product, $companyId) === null) {
                $uncovered++;
            }

            /*
             * 2026-09-12 — the gap PV creates, and the only reason a
             * missing PV is allowed to fall back to the price silently in
             * the calculation: it is not silent HERE.
             *
             * A company that switches to PV and forgets one product keeps
             * being paid — at that product's price, by the old rule, at a
             * rate that was written to mean "of PV". Nothing errors,
             * nothing is logged, and every other signal on this screen
             * says ready. Asked through CommissionBasisResolver rather
             * than re-read from the column so this banner and the money
             * cannot disagree about what "missing" means.
             */
            if ($this->commissionBasisResolver->isPointValueMissing($product, $company)) {
                $pointValueGaps++;
            }

            $planType = $product->effectivePlanType($company);
            $planTypesInUse[$planType->value] = $planType;

            /*
             * Unilevel and Affiliate are the two plans that pay the upline out
             * of commission_override_rules; on the other four the upline is
             * paid by that plan's own structure, so counting them here would
             * invent a gap. A missing leader rate is never red — the agent who
             * closed the deal IS paid — which is why it only ever contributes
             * to 'incomplete'.
             */
            if (in_array($planType, [CommissionPlanType::Unilevel, CommissionPlanType::Affiliate], true)
                && $this->resolveOverrideRuleForCompany($product, $companyId) === null) {
                $leaderGaps++;
            }
        }

        $total = $products->count();
        $covered = $total - $uncovered;

        /*
         * 2026-09-13 (owner: "แดงตลอด ผมยังอยากให้ตั้งค่าบริษัทอยู่ดี").
         *
         * THE COMPANY-WIDE DEFAULT IS NOW REQUIRED, NOT MERELY ADVISABLE.
         *
         * It was possible — and still is, through the copy feature or legacy
         * data — for every product to carry its own rate while the company has
         * no fallback at all. Nothing is unpaid in that state, so this is
         * never RED: a deal closing right now does pay somebody, and red is
         * reserved for the sentence "ดีลที่ปิดได้จะไม่มีใครได้เงิน".
         *
         * But it is a gap the owner wants closed, and the reason is the next
         * product. The moment somebody adds one, it has no rate and nothing
         * catches it — the safety net that step 3.1 exists to be was never
         * hung. So it counts as incomplete, and the settings screen locks 3.2
         * behind it (CommissionPlansView's `subStepThreeOneDone`).
         *
         * ASKED HERE rather than only on the screen, deliberately: without
         * this, the banner would say ready while the screen showed 3.1 red —
         * the two-answers-about-money problem this whole Service exists to
         * remove.
         */
        $companyDefaultMissing = ! CommissionRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('product_id')
            ->whereNull('product_category_id')
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->exists();

        $expired = $this->countRulesOutsideToday($companyId, 'expired');
        $future = $this->countRulesOutsideToday($companyId, 'future');
        $overlapping = $this->countOverlappingLiveRules($companyId);
        $unsetStructures = $this->unsetStructuralPlans($companyId, $planTypesInUse);

        $issues = [];

        if ($uncovered > 0) {
            $issues[] = [
                'code' => 'products_without_rate',
                'label' => "สินค้า {$uncovered} จาก {$total} รายการยังไม่มีอัตราค่าแนะนำที่ใช้ได้",
                'count' => $uncovered,
            ];
        }

        if ($companyDefaultMissing) {
            $issues[] = [
                'code' => 'company_default_missing',
                'label' => 'ยังไม่มีค่าเริ่มต้นทั้งบริษัท — สินค้าที่เพิ่มใหม่จะไม่มีอัตราใดรองรับ',
                'count' => 1,
            ];
        }

        if ($overlapping > 0) {
            $issues[] = [
                'code' => 'rules_overlapping',
                'label' => "มีอัตราค่าแนะนำซ้อนทับกันในขอบเขตเดียวกัน {$overlapping} รายการ — ระบบจะหยิบอันไหนก็ได้ ทำนายไม่ได้",
                'count' => $overlapping,
            ];
        }

        if ($expired > 0) {
            $issues[] = [
                'code' => 'rules_expired',
                'label' => "อัตราค่าแนะนำที่หมดอายุแล้ว {$expired} รายการ",
                'count' => $expired,
            ];
        }

        if ($future > 0) {
            $issues[] = [
                'code' => 'rules_not_yet_effective',
                'label' => "อัตราค่าแนะนำที่ยังไม่ถึงวันเริ่มใช้ {$future} รายการ",
                'count' => $future,
            ];
        }

        if ($unsetStructures !== []) {
            $names = implode(' · ', $unsetStructures);
            $issues[] = [
                'code' => 'plan_structure_missing',
                'label' => "ยังไม่ได้ตั้งค่าโครงสร้าง {$names} — สมาชิกผู้ขายได้ แต่ชั้นบนจะไม่ได้อะไร",
                'count' => count($unsetStructures),
            ];
        }

        if ($pointValueGaps > 0) {
            $issues[] = [
                'code' => 'point_value_missing',
                'label' => "บริษัทนี้คิดค่าแนะนำจาก PV แต่สินค้า {$pointValueGaps} รายการยังไม่ได้กำหนด PV — ระบบจะคิดจากราคาขายไปก่อน",
                'count' => $pointValueGaps,
            ];
        }

        if ($leaderGaps > 0) {
            $issues[] = [
                'code' => 'leader_rate_missing',
                'label' => "สินค้า {$leaderGaps} รายการยังไม่มีอัตราหัวหน้าทีม — หัวหน้าจะไม่ได้ส่วนแบ่งจากดีลนั้น",
                'count' => $leaderGaps,
            ];
        }

        /*
         * 2026-09-24 — the five silent failures of the Stairstep plan the
         * owner asked about, and the reason they belong on THIS banner.
         *
         * Every one of them is a config state the platform accepts, pays
         * money under, and never mentions: a mixed-type ladder whose pairs
         * are skipped, rates that do not rise with volume so the
         * differential lands at zero, two ranks on one threshold, no
         * threshold-0 rank to fall back on, and a seller rate that no longer
         * agrees with the ladder it is supposed to telescope into. The same
         * shape as the gaps already listed above — nothing errors, nobody is
         * told, and it surfaces weeks later as somebody asking where their
         * money went.
         *
         * All AMBER, never red, by this Service's own rule: in each of them
         * the agent who closed the deal IS paid. Red stays reserved for
         * "ดีลที่ปิดได้จะไม่มีใครได้เงิน".
         */
        $stairstepIssues = isset($planTypesInUse[CommissionPlanType::StairstepBreakaway->value])
            ? $this->stairstepIssues($companyId)
            : [];

        $issues = [...$issues, ...$stairstepIssues];

        $state = $this->resolveState($total, $covered, $issues);

        return $this->payload($state, $this->resolveBlockingStep($state, $uncovered, $overlapping, $unsetStructures, $leaderGaps, $pointValueGaps, $companyDefaultMissing, $stairstepIssues), $total, $covered, $issues, $canFix);
    }

    /**
     * THE THREE STATES, and the line between red and amber.
     *
     * RED ('missing') is reserved for one sentence being true: a deal that
     * closes right now pays NOBODY. That is products existing and not one of
     * them resolving to a live rate — whether the company holds no rate at
     * all, or holds several that all expired, which look identical to a count
     * and are identical to an agent.
     *
     * AMBER ('incomplete') is everything that is partly configured: some
     * products covered and some not, rates that are expired or not yet
     * effective, two live rates fighting over the same scope, a plan whose
     * structure was never filled in, a leader who will silently be skipped.
     *
     * The reason to keep that line sharp is that a red banner which also
     * means "something could be tidier" stops being read, and the ONE thing
     * this banner exists to survive is being ignored.
     *
     * @param  list<array{code: string, label: string, count: int}>  $issues
     */
    private function resolveState(int $total, int $covered, array $issues): string
    {
        // $total is never 0 here — forCompany() returns early for a company
        // with nothing to sell, and the guard is kept explicit so this cannot
        // silently start dividing "0 covered of 0" into red.
        if ($total > 0 && $covered === 0) {
            return 'missing';
        }

        return $issues === [] ? 'ready' : 'incomplete';
    }

    /**
     * WHICH STEP TO GO FIX, worst-first — the same order
     * CommissionPlansView's 4-step flow uses, because the banner's whole job
     * is to hand somebody over to that screen at the right step.
     *
     * No rate at all beats a missing structure (a product with no rate pays
     * NOBODY, which makes every other observation about it irrelevant), and
     * both beat a missing leader rate (where the agent is still paid).
     * Step 1 is not reachable from here — it belongs to the "ทุกบริษัท" case
     * in aggregate(), which is the only situation where picking a company is
     * the blocking action.
     *
     * @param  list<string>  $unsetStructures
     * @param  list<array{code: string, label: string, count: int}>  $stairstepIssues
     */
    private function resolveBlockingStep(string $state, int $uncovered, int $overlapping, array $unsetStructures, int $leaderGaps, int $pointValueGaps, bool $companyDefaultMissing, array $stairstepIssues = []): ?int
    {
        if ($state === 'ready') {
            return null;
        }

        if ($uncovered > 0 || $overlapping > 0 || $companyDefaultMissing) {
            return 3;
        }

        if ($unsetStructures !== []) {
            return 2;
        }

        /*
         * Step 2 — the rank ladder is edited there, and so is the seller
         * rate's counterpart. Ranked BELOW the rate gaps above for the usual
         * reason: a product with no rate pays nobody, while a broken ladder
         * still pays the seller. Ranked ABOVE the leader-rate gap because a
         * ladder finding can silence an entire chain rather than one hop.
         */
        if ($stairstepIssues !== []) {
            return 2;
        }

        /*
         * 2026-09-12 — step 2, not step 1, and that is where PV is edited.
         * The basis switch and the per-product PV figures are one decision
         * ("how is this company's commission measured") and they are
         * answered on the same screen; sending an admin to step 1 to pick
         * a company they have already picked would be the banner failing
         * at the only job it has.
         */
        if ($pointValueGaps > 0) {
            return 2;
        }

        if ($leaderGaps > 0) {
            return 4;
        }

        // Expired / not-yet-effective rates with full coverage today: the
        // rates themselves are step 3's business, so that is where the fix is.
        return 3;
    }

    /**
     * Only products THIS COMPANY CAN ACTUALLY SELL count toward the total.
     *
     * Product::isSellableBy() is the one predicate that knows the whole rule
     * (active, plus — for a platform-owned row, ADR-040 — whether this
     * company switched it on in company_product_settings). Re-deriving it
     * here with a join would be a second copy of a rule that already moved
     * once; asking the model costs one extra query per SHARED product and
     * this endpoint is fetched once per admin session per company.
     *
     * withoutGlobalScope(SharedOrTenantScope::class) and an explicit
     * company filter for the same reason the rule lookup is hand-scoped: a
     * Super Admin's ambient scope filters nothing, so "this company's
     * products" has to be said out loud. Note the scope's own class name —
     * TenantScope::class does NOT remove it (see Product's docblock).
     *
     * @return Collection<int, Product>
     */
    private function sellableProducts(Company $company): Collection
    {
        return Product::withoutGlobalScope(SharedOrTenantScope::class)
            ->where(fn ($query) => $query->where('company_id', $company->id)->orWhereNull('company_id'))
            ->get()
            ->filter(fn (Product $product) => $product->isSellableBy((int) $company->id))
            ->values();
    }

    /**
     * The mirror of CommissionService::resolveCommissionRule() — see this
     * class's docblock for what it costs if the two ever drift apart.
     *
     * Written as three queries in the same order rather than resolved in
     * memory from one preloaded set, deliberately: the date predicates are
     * where a re-implementation goes subtly wrong (`effective_to >= now()`
     * compares a DATE column against a DATETIME, so a rate ending today is
     * already dead by lunchtime — surprising, but it is what money does, and
     * a banner that disagreed with money would be worse than no banner).
     * Same SQL, same answer, and the cost is bounded by the product count on
     * a request made once per session.
     */
    private function resolveRuleForCompany(Product $product, int $companyId): ?CommissionRule
    {
        $base = fn () => CommissionRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->orderByDesc('effective_from');

        $rule = $base()->where('product_id', $product->id)->first();

        if ($rule) {
            return $rule;
        }

        if ($product->category_id) {
            $rule = $base()->whereNull('product_id')->where('product_category_id', $product->category_id)->first();

            if ($rule) {
                return $rule;
            }
        }

        return $base()->whereNull('product_id')->whereNull('product_category_id')->first();
    }

    /**
     * The same mirror for CommissionService::resolveOverrideRule() — the
     * team-leader rate. Identical scope order by design (TASK-214, human
     * ruling 2026-08-19: "ตามที่คุณเสนอ"), and kept as its own method here
     * for exactly the reason the Service keeps it as its own method: two
     * resolution orders in one system is a hole nobody remembers.
     */
    private function resolveOverrideRuleForCompany(Product $product, int $companyId): ?CommissionOverrideRule
    {
        $base = fn () => CommissionOverrideRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->orderByDesc('effective_from');

        $rule = $base()->where('product_id', $product->id)->first();

        if ($rule) {
            return $rule;
        }

        if ($product->category_id) {
            $rule = $base()->whereNull('product_id')->where('product_category_id', $product->category_id)->first();

            if ($rule) {
                return $rule;
            }
        }

        return $base()->whereNull('product_id')->whereNull('product_category_id')->first();
    }

    /**
     * Rates that exist but do not apply today.
     *
     * This is the number a `count() > 0` health check gets wrong, and the
     * single clearest reason this endpoint exists instead of a reuse of
     * /config-health-report: a company whose only rate expired last month has
     * a non-zero commission_rules count and pays nobody. Counting them
     * separately from coverage also lets the banner say something an admin
     * can act on — "หมดอายุแล้ว 3 รายการ" points at rows to renew, where
     * "ไม่ครบ" alone points at nothing.
     */
    private function countRulesOutsideToday(int $companyId, string $side): int
    {
        $query = CommissionRule::withoutGlobalScopes()->where('company_id', $companyId);

        if ($side === 'expired') {
            return (int) $query->whereNotNull('effective_to')->where('effective_to', '<', now())->count();
        }

        return (int) $query->where('effective_from', '>', now())->count();
    }

    /**
     * Two or more rates live TODAY in the SAME scope.
     *
     * CommissionRuleService::assertNoOverlap() prevents this for new rows, so
     * what is counted here is legacy data and rows created before that guard
     * — which still resolve, because resolveCommissionRule()'s
     * "most recent effective_from wins" tiebreak always picks one. That is
     * the danger: the money moves, at an amount nobody chose, into a ledger
     * row that may never be corrected (BR-4). Ranked beside "no rate at all"
     * on the settings screen for that reason, and amber rather than red here
     * only because somebody IS paid.
     *
     * Grouped in PHP rather than SQL because "same scope" is a NULL-sensitive
     * pair (product_id, product_category_id) and GROUP BY treats NULLs in a
     * way that differs between drivers; the row count is a company's rate
     * table, not a data set.
     */
    private function countOverlappingLiveRules(int $companyId): int
    {
        $live = CommissionRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->get(['id', 'product_id', 'product_category_id']);

        $byScope = $live->groupBy(fn (CommissionRule $rule) => ($rule->product_id ?? 'null').':'.($rule->product_category_id ?? 'null'));

        // The COUNT is "how many rows are in a contested scope", not "how many
        // scopes are contested": an admin has to open every one of those rows
        // to decide which survives.
        return $byScope->filter(fn (Collection $rules) => $rules->count() > 1)->flatten()->count();
    }

    /**
     * Plan types at least one sellable product resolves to, whose
     * company-wide structure was never configured.
     *
     * Only asked about plan types actually IN USE — a company on Unilevel
     * makes none of these queries, and is never told it is missing a Binary
     * setting it has no use for.
     *
     * Unilevel and Affiliate have no structural singleton at all: both are
     * paid entirely out of rate tables (commission_rules for the agent,
     * commission_override_rules for the leader). Claiming a gap for them
     * would invent one that cannot exist, which is the fastest way to make
     * an amber banner meaningless.
     *
     * These are the same four probes CommissionPlansView.vue fires
     * client-side in loadReadinessProbe(), moved server-side so the banner in
     * the app shell and the banner on the settings screen are one answer
     * rather than two that agree most of the time.
     *
     * @param  array<string, CommissionPlanType>  $planTypesInUse
     * @return list<string>
     */
    private function unsetStructuralPlans(int $companyId, array $planTypesInUse): array
    {
        $missing = [];

        foreach ($planTypesInUse as $planType) {
            $ready = match ($planType) {
                CommissionPlanType::Binary => CommissionBinarySetting::withoutGlobalScopes()->where('company_id', $companyId)->exists(),
                CommissionPlanType::Matrix => CommissionMatrixSetting::withoutGlobalScopes()->where('company_id', $companyId)->exists(),
                // Depth alone pays nobody — a generation slot with no rate row
                // is consumed silently by GenerationCommissionService, so both
                // halves have to exist before this counts as configured.
                CommissionPlanType::Generation => CommissionGenerationSetting::withoutGlobalScopes()->where('company_id', $companyId)->exists()
                    && CommissionGenerationRule::withoutGlobalScopes()->where('company_id', $companyId)->exists(),
                CommissionPlanType::StairstepBreakaway => AgentRank::withoutGlobalScopes()->where('company_id', $companyId)->exists(),
                // Unilevel and Affiliate — see this method's docblock.
                default => true,
            };

            if (! $ready) {
                $missing[] = $this->planTypeLabel($planType);
            }
        }

        return $missing;
    }

    /**
     * EVERYTHING THAT CAN BE WRONG WITH A STAIRSTEP SETUP WITHOUT SAYING SO.
     *
     * Three sources, deliberately separated:
     *
     *   · the LADDER's own invariants, asked of AgentRankLadderInspector —
     *     the identical object the save path consults, so an admin cannot be
     *     refused a save for a reason the banner never mentioned, or shown a
     *     banner about a state the form was happy to write;
     *   · the AGENTS, which the inspector cannot see: how many currently
     *     hold no rank at all;
     *   · the SELLER RATE, which lives in a different table entirely and is
     *     the one gap that makes the plan's central promise untrue.
     *
     * @return list<array{code: string, label: string, count: int}>
     */
    private function stairstepIssues(int $companyId): array
    {
        $ranks = AgentRank::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // No ladder at all is already reported as a missing plan structure
        // by unsetStructuralPlans(). Saying it twice in two vocabularies is
        // how a banner stops being read.
        if ($ranks->isEmpty()) {
            return [];
        }

        $issues = [];
        $findings = $this->ladderInspector->inspect(AgentRankLadderInspector::rungsFrom($ranks));

        foreach ($findings as $code => $count) {
            $issues[] = [
                // Prefixed so a frontend can tell a ladder finding from the
                // rate-table findings above without parsing the label.
                'code' => 'stairstep_'.$code,
                'label' => $this->ladderInspector->label($code, $count),
                'count' => $count,
            ];
        }

        $entryRank = $ranks->firstWhere('volume_threshold', 0);

        $unranked = (int) User::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('role', UserRole::Agent)
            ->whereNull('current_rank_id')
            ->count();

        if ($unranked > 0) {
            /*
             * Not an error on its own — current_rank_id starts null on every
             * new agent and only the scheduled recalculation writes it, so a
             * company will normally always have a few. It is reported because
             * the CONSEQUENCE differs entirely depending on the ladder: with
             * a threshold-0 rank these agents are treated as holding it
             * (owner choice 2ก) and nothing is lost; without one, every sale
             * they make pays their manager nothing, and an admin has no way
             * to see how many people that is.
             */
            $issues[] = [
                'code' => 'stairstep_unranked_agents',
                // ADR-043 added the second half of each sentence: an
                // un-ranked agent's OWN share now differs too, because the
                // rank ladder cannot price a rank they do not hold.
                'label' => $entryRank !== null
                    ? "มีตัวแทน {$unranked} คนที่ยังไม่มีขั้น — ส่วนของหัวหน้าจะคิดเท่ากับขั้นเกณฑ์ ฿0 ไปก่อน และตัวพวกเขาเองจะได้ตามอัตราตัวแทนผู้ขาย จนกว่ารอบคำนวณอันดับถัดไปจะทำงาน"
                    : "มีตัวแทน {$unranked} คนที่ยังไม่มีขั้น และบริษัทนี้ไม่มีขั้นเกณฑ์ ฿0 — ดีลของพวกเขาจะไม่จ่ายส่วนต่างให้หัวหน้าเลย ส่วนตัวพวกเขาเองยังได้ตามอัตราตัวแทนผู้ขาย",
                'count' => $unranked,
            ];
        }

        $mismatch = $this->sellerRateMismatch($companyId, $ranks, $entryRank);

        if ($mismatch !== null) {
            $issues[] = $mismatch;
        }

        return $issues;
    }

    /**
     * THE SELLER RATE AND THE LADDER DISAGREEING (owner choice 1ก).
     *
     * A Stairstep chain pays the seller their own rate out of
     * commission_rules, then pays each manager only the difference between
     * their rank and the one below. Those differences telescope, so the
     * company's total outlay is exactly the highest rank reached in that
     * chain — but ONLY while the seller's rate equals the bottom rung. Set
     * them differently and every sale quietly costs (seller − bottom rung)
     * more or less than the number the owner thought they were capping at.
     *
     * ═══ NARROWED BY ADR-043 (owner choice ค, 2026-09-24) ═══
     *
     * A ranked seller is now paid their RANK's rate, so for them the
     * telescoping closes by construction and commission_rules does not
     * enter into it. The gap survives in exactly one place: an agent who
     * holds NO rank yet — every recruit, until the scheduled recalculation
     * first runs — is still paid the flat rate, and their sales are the
     * ones whose chain total drifts off the top rung.
     *
     * So this is no longer "every sale is mispriced"; it is "the first
     * sales of every new agent are". Narrower, still real, and worth
     * saying precisely: an admin who reads the old wording after ADR-043
     * would go hunting for a problem that is not there on most sales.
     *
     * Reported rather than enforced, which is the whole of choice 1ก: the
     * two values legitimately answer different questions (commission_rules
     * can price per product and per category, agent_ranks cannot price
     * anything per product at all), so a company may mean this. What it may
     * not do is mean it by accident.
     *
     * Percentage rules only. A fixed-satang seller rate and a percentage
     * ladder cannot be compared without a sale to apply them to, and
     * inventing one to put a number on the banner would be exactly the
     * guessing this file's mirror rule exists to prevent.
     *
     * @param  Collection<int, AgentRank>  $ranks
     * @return array{code: string, label: string, count: int}|null
     */
    private function sellerRateMismatch(int $companyId, Collection $ranks, ?AgentRank $entryRank): ?array
    {
        // Nothing to compare against — NO_ENTRY_RANK already carries that.
        if ($entryRank === null || $entryRank->rate_type !== CommissionRateType::Percentage) {
            return null;
        }

        $liveRules = CommissionRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->get(['rate_type', 'rate_value']);

        $mismatched = $liveRules
            ->filter(fn (CommissionRule $rule) => $rule->rate_type === CommissionRateType::Percentage
                && (int) $rule->rate_value !== (int) $entryRank->rate_value)
            ->values();

        if ($mismatched->isEmpty()) {
            return null;
        }

        $entryPct = $this->formatBasisPoints((int) $entryRank->rate_value);
        $distinctRates = $mismatched->pluck('rate_value')->unique()->values();

        /*
         * With exactly one offending rate the banner can do the arithmetic
         * for the admin, and that number is the entire point of the warning:
         * total = top rank + (seller − bottom rung). With several there is
         * no single answer, so it says how many rows to go look at instead
         * of averaging them into a figure nobody's sale will ever produce.
         */
        if ($distinctRates->count() === 1) {
            $topRankValue = (int) $ranks
                ->filter(fn (AgentRank $rank) => $rank->rate_type === CommissionRateType::Percentage)
                ->max('rate_value');

            $sellerValue = (int) $distinctRates->first();
            $sellerPct = $this->formatBasisPoints($sellerValue);
            $topPct = $this->formatBasisPoints($topRankValue);
            $resultPct = $this->formatBasisPoints($topRankValue + $sellerValue - (int) $entryRank->rate_value);

            return [
                'code' => 'stairstep_seller_rate_mismatch',
                'label' => "อัตราตัวแทนผู้ขาย {$sellerPct}% ใช้กับคนที่ยังไม่มีขั้นเท่านั้น และไม่เท่ากับขั้นต่ำสุด {$entryPct}% — ดีลแรก ๆ ของตัวแทนใหม่จะทำให้ยอดจ่ายรวมเป็น {$resultPct}% แทนที่จะเป็น {$topPct}% ตามอัตราขั้นสูงสุด",
                'count' => $mismatched->count(),
            ];
        }

        return [
            'code' => 'stairstep_seller_rate_mismatch',
            'label' => "อัตราตัวแทนผู้ขาย {$mismatched->count()} รายการไม่เท่ากับขั้นต่ำสุด {$entryPct}% — อัตราพวกนี้ใช้กับคนที่ยังไม่มีขั้นเท่านั้น ดีลของพวกเขาจะทำให้ยอดจ่ายรวมไม่ตรงกับอัตราขั้นสูงสุด",
            'count' => $mismatched->count(),
        ];
    }

    /** Basis points as a human percentage: 500 -> "5", 1250 -> "12.5". */
    private function formatBasisPoints(int $basisPoints): string
    {
        $formatted = number_format($basisPoints / 100, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    /** Thai plan names, matching CommissionPlansView.vue's own planTypeLabels. */
    private function planTypeLabel(CommissionPlanType $planType): string
    {
        return match ($planType) {
            CommissionPlanType::Unilevel => 'Unilevel',
            CommissionPlanType::Binary => 'Binary',
            CommissionPlanType::Matrix => 'Matrix',
            CommissionPlanType::StairstepBreakaway => 'อันดับ (Stairstep)',
            CommissionPlanType::Generation => 'Generation',
            CommissionPlanType::Affiliate => 'พันธมิตร (Affiliate)',
        };
    }

    /**
     * @param  list<array{code: string, label: string, count: int}>  $issues
     * @return array{state: string, blocking_step: int|null, products_total: int, products_covered: int, issues: list<array{code: string, label: string, count: int}>, can_fix: bool}
     */
    private function payload(string $state, ?int $blockingStep, int $productsTotal, int $productsCovered, array $issues, bool $canFix): array
    {
        return [
            'state' => $state,
            'blocking_step' => $blockingStep,
            'products_total' => $productsTotal,
            'products_covered' => $productsCovered,
            'issues' => $issues,
            'can_fix' => $canFix,
        ];
    }
}
