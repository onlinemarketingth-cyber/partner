<?php

namespace App\Http\Requests\Catalog;

use App\Enums\AffiliateOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Http\Requests\Concerns\HandlesRichText;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// BR-3: price_satang must be a non-negative integer — no floats accepted.
// brand_id/category_id must belong to the SAME company as the product
// (never trust the client to only submit its own tenant's IDs).
class StoreProductRequest extends FormRequest
{
    use Concerns\ChoosesPlatformOrCompany;
    use Concerns\ValidatesProductTaxonomy;
    use HandlesRichText;

    protected function prepareForValidation(): void
    {
        // 2026-09-09 — cleaned before any rule sees it, so validation runs
        // against exactly what will be stored (see App\Support\RichText).
        $this->sanitizeRichText(['description', 'spec_description']);
        $this->stripPlatformFlagUnlessSuperAdmin();
    }

    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    /**
     * The company this product will belong to — Super Admin must supply
     * it explicitly (they aren't scoped to one company); everyone else
     * is always forced to their own, regardless of what's submitted.
     */
    protected function effectiveCompanyId(): ?int
    {
        return $this->ownerCompanyId();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->effectiveCompanyId();

        $platform = $this->wantsPlatformRow();

        return $this->ownershipRules() + [
            /*
             * Own or platform-owned — see ValidatesProductTaxonomy for why an
             * exact company match stopped being the right question (ADR-040).
             *
             * For a PLATFORM product $companyId is null and the same rule
             * collapses to "platform rows only", which is the invariant a
             * shared product needs: it belongs to nobody, so it cannot carry
             * one company's brand.
             */
            'brand_id' => ['required', 'integer', $this->taxonomyRule('brands', $companyId)],
            'category_id' => ['required', 'integer', $this->taxonomyRule('product_categories', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'price_satang' => ['required', 'integer', 'min:0'], // BR-3 — never accept a float
            /*
             * 2026-09-12 — PV / commissionable value. Optional at create
             * time on purpose: a product is catalogued before anybody has
             * decided what it is worth in points, and a required field
             * here would make "add a product" depend on a commission
             * decision that only a Super Admin may make. The readiness
             * banner is what stops it being forgotten — but only once the
             * company is actually on the PV basis. See UpdateProductRequest
             * for why this is Super-Admin-only rather than quietly ignored.
             */
            'pv_satang' => [
                'sometimes',
                'nullable',
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                'integer',
                'min:0',
            ],
            'description' => $this->richTextRules(15000),
            'spec_description' => $this->richTextRules(15000), // ADR-008 — free-text spec narrative, additive alongside description
            'is_active' => ['sometimes', 'boolean'],
            // ADR-011/TASK-027 — nullable = inherit the company's plan
            // type (Product::effectivePlanType()). Omitting the field
            // entirely also means "inherit", same as explicit null.
            /*
             * REQUIRED for a platform product, and only for one.
             *
             * A company product with no plan type inherits its company's.
             * A platform product has no company to inherit from, so
             * Product::effectivePlanType() throws rather than guess — the plan
             * type decides HOW commission is calculated, and a wrong guess
             * lands in an immutable ledger (BR-2/BR-4). Asking here is the
             * cheapest place to keep that "unreachable" actually unreachable;
             * catalog:promote-products writes the value down for the same
             * reason.
             */
            'commission_plan_type' => [
                Rule::requiredIf(fn () => $platform),
                'nullable',
                Rule::enum(CommissionPlanType::class),
            ],
            // TASK-194 §3.1/§3.4 — nullable/omitted = 'additive' at
            // calculation time (Product::effectiveAffiliateOverrideMode()).
            // Only meaningful when effectivePlanType() is Affiliate.
            'affiliate_override_mode' => ['nullable', Rule::enum(AffiliateOverrideMode::class)],
            // TASK-197 §2.1 — nullable/omitted = "not yet configured";
            // gets stamped automatically by CommissionRuleService::create()
            // once this product's first commission_rules row is created.
            'commission_rate_type' => ['nullable', Rule::enum(CommissionRateType::class)],
            // ADR-026 §3.3 (TASK-132) — nullable/omitted = inherit the
            // category, then the company, then medical_package_default.
            // BR-6: scoped to the SAME company, exactly like brand_id and
            // category_id above — a product must never be able to point
            // at another tenant's journey (ADR-026 §4 "validated, not
            // assumed"). PipelineTemplateResolver re-checks this at read
            // time too, since a Request is not the only write path.
            /*
              * 2026-09-09 — a shared product CARRIES a journey now.
              *
              * This was `prohibited` for a platform row, on the reasoning that
              * a journey belongs to one company so a shared product cannot
              * hold one. True of the schema at the time; the schema changed
              * (pipeline_templates.company_id is nullable, ADR-040's third
              * platform-owned table after brands and categories) because the
              * consequence was not acceptable: a promoted product had no
              * journey, fell through to the selling company's Medical Package
              * fail-safe, and its share links silently lost the buy button.
              *
              * $companyId is null for a platform row, so journeyRule() offers
              * platform journeys only — the same shape as the brand and
              * category rules directly above.
              */
            'pipeline_template_id' => ['sometimes', 'nullable', 'integer', $this->journeyRule($companyId)],
            // ADR-033 (TASK-189) §2.3/§2.5 — BR-7 admin-editable, never
            // hardcoded. Nullable/omitted = unlimited quota / never
            // expires / no shipping needed. Snapshotted onto
            // order_vouchers at issuance (OrderVoucherService::issueFor()),
            // never read live at redemption time.
            'voucher_usage_quota' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'voucher_validity_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'requires_shipping' => ['sometimes', 'boolean'],
        ];
    }
}
