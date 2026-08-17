<?php

namespace App\Services\Pipeline;

use App\Enums\PipelineStage;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\TenantScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-026 §3.3 (TASK-132) — resolves WHICH pipeline template a product
 * sells under, most-specific-wins:
 *
 *     product.pipeline_template_id
 *       ?? product.category.pipeline_template_id
 *       ?? company.default_pipeline_template_id
 *       ?? the seeded medical_package_default
 *
 * Deliberately the same shape as CommissionService::resolveCommissionRule()
 * (ADR-011 §2 / TASK-028): product -> category -> company-wide default.
 * ADR-026 §4 flags that this is now the third such chain in the codebase
 * (commission scope, plan type, pipeline template) and that a fourth
 * should trigger extracting the pattern — it does NOT justify inventing a
 * second style for this one.
 *
 * BR-6 note, same reasoning as CommissionService::resolveActivePricePromotion():
 * every lookup below filters `company_id` EXPLICITLY rather than leaning
 * on TenantScope alone. TenantScope exempts Super Admin entirely (§5) and
 * no-ops entirely on unauthenticated public routes (AffiliateLeadCaptureService,
 * and TASK-136's public checkout) — so a scope-only filter here would be
 * no filter at all in exactly the contexts that matter most. A template id
 * that does not resolve within the product's own company is treated as
 * absent (and logged), never followed.
 */
class PipelineTemplateResolver
{
    /**
     * Per-INSTANCE memo of resolutions, keyed by the three inputs the
     * chain actually reads (own override / category / company). Added by
     * TASK-136 so ProductResource can expose `effective_pipeline_template`
     * on a paginated catalogue without issuing the whole chain per row:
     * every product in one company that inherits resolves to the same
     * answer, so the key collapses them to a single lookup.
     *
     * Deliberately per-instance, NOT static and NOT a container
     * singleton: a template edited mid-test (or mid-job) must not be
     * served from a cache that outlives the request that read it. The one
     * consumer that needs cross-row reuse shares ONE instance for the
     * length of a single request (see App\Support\RequestScopedService).
     * Same reasoning as PipelineService::$sequenceCache.
     *
     * @var array<string, PipelineTemplate|null>
     */
    private array $resolutionCache = [];

    /**
     * Fail-closed: returns null (never a wrong-tenant or guessed
     * template) when nothing resolves, and logs loudly. ADR-026 §3.3
     * calls the final medical_package_default step a fail-safe that is
     * "never null in practice" — but "in practice" is a seeded-data
     * assumption, not an invariant the schema enforces, so a caller must
     * still handle null rather than this method inventing a journey.
     */
    public function resolveForProduct(Product $product): ?PipelineTemplate
    {
        $companyId = $product->company_id;

        $cacheKey = implode('|', [
            $product->pipeline_template_id ?? '-',
            $product->category_id ?? '-',
            $companyId,
        ]);

        if (array_key_exists($cacheKey, $this->resolutionCache)) {
            return $this->resolutionCache[$cacheKey];
        }

        $template = $this->findInCompany($product->pipeline_template_id, $companyId, "product {$product->id}")
            ?? $this->findInCompany($this->categoryTemplateId($product), $companyId, "category of product {$product->id}")
            ?? $this->findInCompany($this->companyDefaultTemplateId($companyId), $companyId, "company {$companyId} default")
            ?? $this->findSystemDefault($companyId);

        if (! $template) {
            Log::warning("PipelineTemplateResolver: no pipeline template resolved for product {$product->id} (company {$companyId}) — product/category/company scopes are all unset and the seeded '".PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT."' template is missing for this company. Run PipelineTemplateSeeder (ADR-026 §3.3).");
        }

        return $this->resolutionCache[$cacheKey] = $template;
    }

    /**
     * TASK-136 — can a referral created under $template reach
     * `complete_payment` on its very first move?
     *
     * This is the ONE rule that decides whether a product may be checked
     * out by an anonymous customer from a public share link, and it is
     * deliberately DERIVED from the template rather than asked of a
     * hardcoded stage name or a `requires_medical_journey` flag (ADR-026
     * §2 Option A is exactly the shape this ADR rejected).
     *
     * Why "position 1" and not something looser:
     *   - A referral is created AT the entry stage, `complete_registered`,
     *     which CLAUDE.md §4.3 pins to position 0.
     *   - OrderService::confirmPayment() (ADR-026 §3.7) will only accept a
     *     payment when the referral's NEXT stage under its own template is
     *     `complete_payment`, or it is already at/past it.
     *   - So an order minted at creation time is confirmable immediately
     *     if and only if `complete_payment` sits at position 1.
     *
     * Anything else (the seeded `medical_package_default`, or any journey
     * with steps between registration and payment) would let a customer
     * pay for something nobody could then confirm — money in, order stuck.
     * Those products keep the existing view-only share page + lead form
     * (TASK-132 §TASK-136, TASK-137).
     *
     * Null template (the resolver failed closed) → false. §6 fail-closed:
     * an unreadable journey is never a purchasable one.
     */
    public function paymentReachableFromEntry(?PipelineTemplate $template): bool
    {
        if (! $template) {
            return false;
        }

        $sequence = $template->stageSequence();

        return ($sequence[1] ?? null) === PipelineStage::CompletePayment;
    }

    /**
     * The §3.5 + §5-Q1 invariants of a template's stage list, in ONE
     * place so the Form Requests of TASK-134 (template authoring) and
     * TASK-133 can call exactly the same rules the Service enforces.
     *
     * CLAUDE.md §6 "never trust the client": this is deliberately a
     * Service method, not only a Form Request rule. A template missing
     * complete_payment is a silent BR-4 commission outage for every
     * product using it, so it must not be representable via ANY write
     * path — seeder, console command, or a future endpoint that forgets
     * its Request.
     *
     * @param  list<PipelineStage|string>  $stages  ordered, as authored
     *
     * @throws ValidationException
     */
    public function assertValidStageSequence(array $stages): void
    {
        $sequence = $this->normaliseSequence($stages);

        if ($sequence === []) {
            $this->reject('A pipeline template must contain at least the entry stage and complete_payment (CLAUDE.md §4.3 / ADR-026 §3.5).');
        }

        // Each stage at most once (ADR-026 §5 Q1). Also the application
        // half of unique(pipeline_template_id, stage) — checked here so
        // the caller gets a 422 instead of a driver constraint error.
        $values = array_map(fn (PipelineStage $stage) => $stage->value, $sequence);
        if (count($values) !== count(array_unique($values))) {
            $this->reject('Each pipeline stage may appear at most once in a template (ADR-026 §5 Q1).');
        }

        // CLAUDE.md §4.3: "Every template must contain complete_registered
        // (entry) and complete_payment." BR-4 fires at complete_payment
        // and nowhere else, so its absence is not representable.
        foreach ([PipelineStage::CompleteRegistered, PipelineStage::CompletePayment] as $required) {
            if (! in_array($required->value, $values, true)) {
                $this->reject("A pipeline template must contain '{$required->value}' (CLAUDE.md §4.3 / ADR-026 §3.5 — commission (BR-4) triggers at complete_payment, so a template without it would silently stop paying commission).");
            }
        }

        // CONFIRMED by ag-lead 2026-08-08 — ag-dev raised this as a
        // TODO: CONFIRM because §4.3 only annotated complete_registered
        // as "(entry)" without stating a position rule. Ruling: "entry"
        // means position 0, not merely present. A referral is created AT
        // the entry stage (ADR-026 §3.4), so a template whose first stage
        // is something else strands every referral it stamps at a stage
        // its own journey cannot start from. CLAUDE.md §4.3 now says this
        // explicitly; this check is the enforcement of a written rule,
        // not an inference.
        if ($values[0] !== PipelineStage::CompleteRegistered->value) {
            $this->reject("'complete_registered' must be the FIRST stage of a pipeline template — it is the entry stage every referral is created at (CLAUDE.md §4.3, ADR-026 §3.4).");
        }

        // ADR-026 §5 Q1: the three post-sale stages are optional and
        // unordered as a group, but all must sit AFTER complete_payment —
        // "a post-sale step before the sale is closed is not a thing".
        $paymentIndex = array_search(PipelineStage::CompletePayment->value, $values, true);
        foreach ($sequence as $index => $stage) {
            if ($stage->isPostSale() && $index < $paymentIndex) {
                $this->reject("'{$stage->value}' is a post-sale stage and must come after 'complete_payment' (ADR-026 §5 Q1).");
            }
        }

        // ag-lead ruling 2026-08-08, resolving the TODO: CONFIRM ag-dev
        // raised in PipelineService::nextStageFor() (TASK-133).
        //
        // ongoing_next_meeting SELF-LOOPS — it is the only stage whose
        // "next" is itself, with the 2nd/3rd/4th count carried on
        // referrals.meeting_number rather than as separate enum cases
        // (§4.3). A self-looping stage placed anywhere but last is an
        // inescapable trap: the referral can never reach the stages the
        // template lists after it, so the template would silently claim a
        // journey it cannot deliver.
        //
        // This is an architectural consequence of the self-loop, not a
        // business value to be asked about — hence a rule here rather
        // than another TODO. Making it representable-but-broken would
        // just move the failure from config time to a live customer.
        $ongoingIndex = array_search(PipelineStage::OngoingNextMeeting->value, $values, true);
        if ($ongoingIndex !== false && $ongoingIndex !== count($values) - 1) {
            $this->reject("'ongoing_next_meeting' may only be the LAST stage of a template — it self-loops (§4.3), so any stage listed after it is unreachable (ag-lead ruling 2026-08-08, ADR-026 §3.6).");
        }
    }

    /**
     * @param  list<PipelineStage|string>  $stages
     * @return list<PipelineStage>
     */
    private function normaliseSequence(array $stages): array
    {
        $normalised = [];

        foreach ($stages as $stage) {
            if ($stage instanceof PipelineStage) {
                $normalised[] = $stage;

                continue;
            }

            $case = is_string($stage) ? PipelineStage::tryFrom($stage) : null;

            if (! $case) {
                // ADR-026 §2 Option C / §3.2 — stages are a closed enum,
                // never admin-typed strings. Adding a genuinely new stage
                // type is a code change plus an ADR.
                $this->reject('Unknown pipeline stage — a template may only contain stages from App\Enums\PipelineStage (ADR-026 §3.2).');
            }

            $normalised[] = $case;
        }

        return $normalised;
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['stages' => $message]);
    }

    /**
     * Looks a template id up STRICTLY within $companyId (BR-6). A
     * non-null id that does not resolve is a cross-tenant reference or a
     * dangling row — both are treated as "unset" so resolution falls
     * through to the next, safe level, and both are logged because
     * neither should be reachable once the Form Request rules of
     * TASK-132 are in place.
     */
    private function findInCompany(?int $templateId, int $companyId, string $context): ?PipelineTemplate
    {
        if (! $templateId) {
            return null;
        }

        $template = PipelineTemplate::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $companyId)
            ->find($templateId);

        if (! $template) {
            Log::warning("PipelineTemplateResolver: {$context} references pipeline_template {$templateId}, which does not exist within company {$companyId} — ignoring it and falling through to the next scope (BR-6).");
        }

        return $template;
    }

    private function categoryTemplateId(Product $product): ?int
    {
        if (! $product->category_id) {
            return null;
        }

        return ProductCategory::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $product->company_id)
            ->find($product->category_id)?->pipeline_template_id;
    }

    private function companyDefaultTemplateId(int $companyId): ?int
    {
        // Company carries no TenantScope of its own (it IS the tenant
        // boundary — see Company's docblock).
        return Company::find($companyId)?->default_pipeline_template_id;
    }

    private function findSystemDefault(int $companyId): ?PipelineTemplate
    {
        return PipelineTemplate::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $companyId)
            ->where('key', PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT)
            ->first();
    }
}
