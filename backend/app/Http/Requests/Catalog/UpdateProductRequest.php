<?php

namespace App\Http\Requests\Catalog;

use App\Enums\AffiliateOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\Product $product */
        $product = $this->route('product');
        $companyId = $product->company_id;

        return [
            'brand_id' => ['sometimes', 'integer', Rule::exists('brands', 'id')->where('company_id', $companyId)],
            'category_id' => ['sometimes', 'integer', Rule::exists('product_categories', 'id')->where('company_id', $companyId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price_satang' => ['sometimes', 'required', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'spec_description' => ['nullable', 'string'], // ADR-008 — free-text spec narrative, additive alongside description
            'is_active' => ['sometimes', 'boolean'],
            // ADR-011/TASK-027 — explicit null clears the override back
            // to "inherit company default" (Product::effectivePlanType()).
            'commission_plan_type' => ['sometimes', 'nullable', Rule::enum(CommissionPlanType::class)],
            // TASK-194 §3.1/§3.4 — only meaningful when effectivePlanType()
            // is Affiliate (ignored by CommissionService otherwise, same as
            // this task's whole branch); explicit null clears the override
            // back to 'additive' (Product::effectiveAffiliateOverrideMode()).
            'affiliate_override_mode' => ['sometimes', 'nullable', Rule::enum(AffiliateOverrideMode::class)],
            // TASK-197 §2.1 — explicit null clears the setting back to
            // "not yet configured" (a fresh commission_rules row for this
            // product would then re-lock the format on next create()).
            'commission_rate_type' => ['sometimes', 'nullable', Rule::enum(CommissionRateType::class)],
            // ADR-026 §3.3 (TASK-132) — explicit null clears the override
            // back to "inherit from the category/company". BR-6: same
            // company only (ADR-026 §4).
            'pipeline_template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pipeline_templates', 'id')->where('company_id', $companyId)],
            // ADR-033 (TASK-189) §2.3/§2.5 — same contract as
            // StoreProductRequest: explicit null clears the quota/
            // validity override back to unlimited/never-expires.
            'voucher_usage_quota' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'voucher_validity_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'requires_shipping' => ['sometimes', 'boolean'],
        ];
    }
}
