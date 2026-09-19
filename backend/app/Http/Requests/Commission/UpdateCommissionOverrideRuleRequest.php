<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionRateType;
use App\Http\Requests\Catalog\Concerns\ValidatesProductOwnership;
use App\Http\Requests\Catalog\Concerns\ValidatesProductTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommissionOverrideRuleRequest extends FormRequest
{
    /**
     * How deep a level may be priced. Not a business cap on how far the chain
     * pays — that is `companies.max_override_depth`, and NULL there still
     * means uncapped. This only stops a typo (level 9999) creating a row
     * nothing will ever read; CommissionService's own circuit breaker sits at
     * 100 hops for the same defensive reason.
     */
    public const MAX_PRICEABLE_LEVEL = 100;

    use ValidatesProductOwnership;
    use ValidatesProductTaxonomy;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('commission_override_rule'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // TASK-214 — the scope is editable on update, same as the agent
        // rate's. company_id is not: a rule never changes tenant (BR-6).
        $companyId = $this->route('commission_override_rule')?->company_id;

        return [
            'product_id' => [
                'sometimes', 'nullable', 'integer',
                $this->configurableProductRule($companyId),
                Rule::prohibitedIf(fn () => $this->filled('product_category_id')),
            ],
            'product_category_id' => [
                'sometimes', 'nullable', 'integer',
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
                $this->taxonomyRule('product_categories', $companyId),
                Rule::prohibitedIf(fn () => $this->filled('product_id')),
            ],
            'manager_cert_tier_id' => ['sometimes', 'nullable', 'integer', 'exists:cert_tiers,id'],
            'rate_type' => ['sometimes', 'required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['sometimes', 'required', 'integer', 'min:0'],
            // Sending null CLEARS the rate's own mode and puts it back on the
            // company's — see the store request for why that is a real value.
            /*
             * 2026-09-19 — which hop of the chain this rate prices.
             *
             * Omitted / null = every level, which is what every row written
             * before today means and what keeps a company that never touches
             * this computing exactly as before.
             *
             * `override_mode` is refused on a levelled row on purpose. The
             * mode says who FUNDS the override — the company, the sale, or
             * the seller's own commission — and one walk up one chain has one
             * funding model. Letting level 2 deduct from the seller while
             * level 1 was paid by the company would make the seller's own row
             * depend on how deep their upline happened to go, which is not a
             * rate question and has no honest answer.
             */
            'level' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:'.self::MAX_PRICEABLE_LEVEL,
            ],
            'override_mode' => [
                'sometimes',
                'nullable',
                Rule::prohibitedIf(fn () => $this->filled('level')),
                Rule::enum(CommissionOverrideMode::class),
            ],
            'effective_from' => ['sometimes', 'required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
