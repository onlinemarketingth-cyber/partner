<?php

namespace App\Http\Requests\Commission;

use App\Models\CommissionRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-09-13 — POST /commission-rules/copy.
 *
 * Authorized by CommissionRulePolicy::create, not by the commission-settings
 * Ability: what this endpoint produces IS commission_rules rows, so the gate
 * that guards writing one by hand is the honest gate for writing twelve at
 * once. (Both are Super Admin today; asking the right one is what keeps them
 * in step if that ever changes.)
 */
class CopyCommissionRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CommissionRule::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_company_id' => ['required', 'integer', 'exists:companies,id', 'different:to_company_id'],
            /*
             * The TARGET is named explicitly rather than inferred from the
             * caller. A Super Admin has no company of their own to infer from,
             * and inferring it from the active-company header would make a
             * money write depend on a UI preference stored in localStorage.
             */
            'to_company_id' => ['required', 'integer', 'exists:companies,id'],
            /*
             * Defaults to TRUE — the safe direction. A caller that forgets the
             * flag gets the preview, not twelve rate rows. The owner asked for
             * a confirmation step (2026-09-13, "ต้องกดยืนยัน") and this is
             * that requirement expressed where it cannot be skipped by a
             * frontend bug.
             */
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
