<?php

namespace App\Http\Requests\Catalog;

use App\Enums\AffiliateOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// BR-3: price_satang must be a non-negative integer — no floats accepted.
// brand_id/category_id must belong to the SAME company as the product
// (never trust the client to only submit its own tenant's IDs).
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Product::class);
    }

    /**
     * The company this product will belong to — Super Admin must supply
     * it explicitly (they aren't scoped to one company); everyone else
     * is always forced to their own, regardless of what's submitted.
     */
    protected function effectiveCompanyId(): ?int
    {
        return $this->user()->isSuperAdmin()
            ? $this->integer('company_id') ?: null
            : $this->user()->company_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->effectiveCompanyId();

        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')->where('company_id', $companyId)],
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'price_satang' => ['required', 'integer', 'min:0'], // BR-3 — never accept a float
            'description' => ['nullable', 'string'],
            'spec_description' => ['nullable', 'string'], // ADR-008 — free-text spec narrative, additive alongside description
            'is_active' => ['sometimes', 'boolean'],
            // ADR-011/TASK-027 — nullable = inherit the company's plan
            // type (Product::effectivePlanType()). Omitting the field
            // entirely also means "inherit", same as explicit null.
            'commission_plan_type' => ['nullable', Rule::enum(CommissionPlanType::class)],
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
            'pipeline_template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pipeline_templates', 'id')->where('company_id', $companyId)],
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
