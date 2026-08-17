<?php

namespace App\Http\Requests\Catalog;

use App\Enums\CommissionRateType;
use App\Http\Requests\Catalog\Concerns\ValidatesCommissionRateCap;
use App\Http\Requests\Catalog\Concerns\ValidatesCommissionRateTypeConsistency;
use App\Models\CommissionRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// BR-2: rate_value is always an integer (basis points for "percentage",
// satang for "fixed_satang" — BR-3). product_id/product_category_id must
// belong to the same company; cert_tier_id is global so no company check
// needed there. Overlap-with-existing-rule validation lives in
// CommissionRuleService, not here — it needs a query, not just input
// shape.
//
// ADR-011/TASK-028: product_id is no longer required — omitting it (and
// product_category_id) means a company-wide default rule for the cert
// tier. At most ONE of product_id/product_category_id may be set;
// Rule::prohibitedIf enforces that mutual exclusion here so a malformed
// "scoped to both" row can never reach CommissionRuleService.
class StoreCommissionRuleRequest extends FormRequest
{
    use ValidatesCommissionRateCap;
    use ValidatesCommissionRateTypeConsistency;

    public function authorize(): bool
    {
        return $this->user()->can('create', CommissionRule::class);
    }

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
            'cert_tier_id' => ['required', 'integer', 'exists:cert_tiers,id'],
            'product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId),
                Rule::prohibitedIf(fn () => $this->filled('product_category_id')),
            ],
            'product_category_id' => [
                'nullable', 'integer',
                Rule::exists('product_categories', 'id')->where('company_id', $companyId),
                Rule::prohibitedIf(fn () => $this->filled('product_id')),
            ],
            'rate_type' => ['required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['required', 'integer', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            // TASK-024 — fully opt-in: omit both to skip renewal commission
            // entirely for this rule (CommissionService only stamps
            // next_renewal_date when renewal_rate_type is actually set).
            'renewal_rate_type' => ['nullable', 'required_with:renewal_rate_value', Rule::enum(CommissionRateType::class)],
            'renewal_rate_value' => ['nullable', 'required_with:renewal_rate_type', 'integer', 'min:0'],
            'renewal_recurs' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * TASK-196 §2.3 — server-side commission-rate-cap enforcement,
     * independent of the frontend check (spec §4 DoD: "a direct API call
     * must also be rejected, not just the UI"). Runs AFTER the base
     * `rules()` above via `after()`, and bails out if those already
     * failed — a malformed rate_type/rate_value/product_id must not also
     * report a cap message computed from garbage input.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->assertWithinCommissionRateCap(
                $validator,
                $this->filled('product_id') ? $this->integer('product_id') : null,
                $this->input('rate_type'),
                $this->has('rate_value') ? $this->integer('rate_value') : null,
            );

            // TASK-197 §2.2 — server-side "this product's format is
            // already locked in" enforcement, independent of the
            // frontend (same "don't trust the client alone" precedent as
            // the cap check right above). Runs alongside it, not
            // instead — a request can fail both checks at once and the
            // admin should see both messages together.
            $this->assertRateTypeConsistentWithProduct(
                $validator,
                $this->filled('product_id') ? $this->integer('product_id') : null,
                $this->input('rate_type'),
            );
        });
    }
}
