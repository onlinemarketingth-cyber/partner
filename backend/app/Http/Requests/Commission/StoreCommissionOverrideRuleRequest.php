<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionRateType;
use App\Http\Requests\Catalog\Concerns\ValidatesProductOwnership;
use App\Http\Requests\Catalog\Concerns\ValidatesProductTaxonomy;
use App\Models\CommissionOverrideRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// rate_value is always an integer (basis points for "percentage", satang
// for "fixed_satang" — BR-3). Overlap-with-existing-rule validation lives
// in CommissionOverrideRuleService, not here — it needs a query, not just
// input shape (mirrors StoreCommissionRuleRequest).
//
// TASK-214 — the scope pair mirrors StoreCommissionRuleRequest exactly,
// including the mutual prohibition: at most ONE of product_id /
// product_category_id, both omitted = the company-wide default. Written
// as a copy of that rule set rather than an abstraction over it, because
// the two are the same TODAY by explicit decision, not by nature — if the
// leader rate ever needs a dimension the agent rate does not, a shared
// base class would be the thing standing in the way.
//
// manager_cert_tier_id is now `nullable` and is NOT read when resolving a
// payout (human ruling 2026-08-19: "ไม่ต้องผูก"). It is still accepted so
// an operator can annotate a legacy row while collapsing it; new rows
// simply omit it.
class StoreCommissionOverrideRuleRequest extends FormRequest
{
    use ValidatesProductOwnership;
    use ValidatesProductTaxonomy;

    public function authorize(): bool
    {
        return $this->user()->can('create', CommissionOverrideRule::class);
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
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'manager_cert_tier_id' => ['nullable', 'integer', 'exists:cert_tiers,id'],
            'product_id' => [
                'nullable', 'integer',
                $this->configurableProductRule($this->effectiveCompanyId()),
                Rule::prohibitedIf(fn () => $this->filled('product_category_id')),
            ],
            'product_category_id' => [
                'nullable', 'integer',
                /*
                 * 2026-09-14 — taxonomyRule(), not a hand-written
                 * `where('company_id', $companyId)`.
                 *
                 * Owner hit this from the screen: "Anti Aging" sat in the
                 * dropdown, the impact preview listed the three products it
                 * would change, and บันทึก answered "The selected product
                 * category id is invalid."
                 *
                 * The category is PLATFORM-OWNED (`company_id NULL`, ADR-040
                 * §1) because the products in it are — a central product
                 * cannot point at a per-company taxonomy. The old rule
                 * demanded the category belong to this company, so a
                 * category-scoped commission rate was IMPOSSIBLE to save for
                 * any shared catalogue, which is most of them.
                 *
                 * This is the identical failure ValidatesProductOwnership was
                 * written for one field over ("the list offers what the save
                 * refuses"), and ValidatesProductTaxonomy already carries the
                 * cure — these Requests simply never adopted it. Another
                 * company's category stays refused, which was the whole point
                 * of the original rule.
                 */
                $this->taxonomyRule('product_categories', $this->effectiveCompanyId()),
                Rule::prohibitedIf(fn () => $this->filled('product_id')),
            ],
            'rate_type' => ['required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['required', 'integer', 'min:0'],
            /*
             * 2026-09-14 — where THIS rate's money comes from, or omitted /
             * null to follow the company's setting (ขั้นที่ 4's own default).
             *
             * `nullable` and not `required`: null is a MEANING here, not a
             * missing value, and it is the state nearly every rate stays in.
             * Forcing a choice per rate would make every admin answer a
             * company-level question once per product.
             */
            'override_mode' => ['sometimes', 'nullable', Rule::enum(CommissionOverrideMode::class)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
