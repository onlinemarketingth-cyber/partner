<?php

namespace App\Http\Requests\Catalog;

use App\Enums\CommissionRateType;
use App\Http\Requests\Catalog\Concerns\ValidatesCommissionRateCap;
use App\Http\Requests\Catalog\Concerns\ValidatesCommissionRateTypeConsistency;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommissionRuleRequest extends FormRequest
{
    use ValidatesCommissionRateCap;
    use ValidatesCommissionRateTypeConsistency;

    public function authorize(): bool
    {
        $commissionRule = $this->route('commission_rule');

        if (! $this->user()->can('update', $commissionRule)) {
            return false;
        }

        // ADR-036 §5/§6 — same restriction as StoreCommissionRuleRequest;
        // checked against the rule's own (immutable) product_id since
        // CommissionRuleService::update() never lets product_id change.
        if (! $this->user()->isSuperAdmin() && $commissionRule->product_id !== null) {
            if ($commissionRule->product?->catalog_item_id !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rate_type' => ['sometimes', 'required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['sometimes', 'required', 'integer', 'min:0'],
            'effective_from' => ['sometimes', 'required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            // TASK-024 — see StoreCommissionRuleRequest's comment. No
            // 'sometimes' here (unlike rate_type/rate_value above): these
            // two are a pair, and 'sometimes' + 'required_with' don't
            // combine safely when the REQUIRED side of the pair is the
            // one missing from the request — 'nullable' alone already
            // lets a PUT omit both to mean "no renewal rate", the same
            // as StoreCommissionRuleRequest.
            'renewal_rate_type' => ['nullable', 'required_with:renewal_rate_value', Rule::enum(CommissionRateType::class)],
            'renewal_rate_value' => ['nullable', 'required_with:renewal_rate_type', 'integer', 'min:0'],
            'renewal_recurs' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * TASK-196 §2.3 — same cap enforcement as StoreCommissionRuleRequest,
     * but against the RULE'S OWN EXISTING product_id/rate_type/rate_value
     * for whichever of the three (rate_type, rate_value) is not present
     * in this PUT — Service::update()'s own scope-immutability comment
     * confirms product_id can never change on update, so the route-bound
     * model's product_id is always the right one to check against.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $commissionRule = $this->route('commission_rule');

            $rateType = $this->has('rate_type') ? $this->input('rate_type') : $commissionRule->rate_type;
            $rateValue = $this->has('rate_value') ? $this->integer('rate_value') : $commissionRule->rate_value;

            $this->assertWithinCommissionRateCap(
                $validator,
                $commissionRule->product_id,
                $rateType,
                $rateValue,
            );

            // TASK-197 §2.2 — same enforcement as StoreCommissionRuleRequest,
            // against the rule's own (immutable) product_id. Note this can
            // only ever fire when rate_type is EXPLICITLY changed in the
            // PUT to something other than the product's locked-in format —
            // a PUT that omits rate_type reuses $commissionRule->rate_type,
            // which by construction already matches (or the row predates
            // this task and the product's own commission_rate_type is
            // still null, in which case the check below is a no-op).
            $this->assertRateTypeConsistentWithProduct(
                $validator,
                $commissionRule->product_id,
                $rateType,
            );
        });
    }
}
