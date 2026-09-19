<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The per-company minimum withdrawal (2026-08-27).
 *
 * NULLABLE IS A REAL ANSWER, not a missing one: a company that wants to
 * allow any amount says so by clearing the field, and the application must
 * never substitute a floor of its own invention. Hence `present` + nullable
 * rather than `sometimes` — the caller has to state the value, including
 * stating that it is empty.
 */
class UpdateCommissionWithdrawalSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsCommissionWithdrawalUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // BR-3 — satang, integer, never a float. min:0 rather than min:1
            // so "0" and "no minimum" stay distinguishable: 0 means the
            // company set a floor of zero, null means it set none.
            'min_withdrawal_satang' => ['present', 'nullable', 'integer', 'min:0'],
            /*
             * 2026-09-19 — ภาษีหัก ณ ที่จ่าย, in basis points (300 = 3.00%),
             * the scale every other rate in this system uses.
             *
             * `sometimes`, not `present` like the field above: the screen
             * that saves the minimum today does not know about this field,
             * and making it required would break the save it already does.
             * Absent means "leave it alone"; an explicit null means "no
             * withholding", which is a real instruction and the state every
             * company is in now.
             *
             * max:10000 is 100% — an arithmetic ceiling, not a tax opinion.
             * The rate itself is BR-7 and the owner's: nothing here defaults
             * it, suggests one, or validates it against a table of rates,
             * because which rate is correct depends on the classification of
             * the payment and on the payee, and a system guessing at that
             * produces a filing error with a penalty attached.
             */
            'wht_rate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            // Super Admin acts on a chosen company; a Company Admin's own
            // company is taken from their account and this is ignored. Same
            // shape as UpdateCommissionBinarySettingRequest.
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'min_withdrawal_satang.integer' => 'ยอดขั้นต่ำไม่ถูกต้อง',
            'min_withdrawal_satang.min' => 'ยอดขั้นต่ำต้องไม่ติดลบ',
            'wht_rate.integer' => 'อัตราภาษีหัก ณ ที่จ่ายไม่ถูกต้อง',
            'wht_rate.min' => 'อัตราภาษีหัก ณ ที่จ่ายต้องไม่ติดลบ',
            'wht_rate.max' => 'อัตราภาษีหัก ณ ที่จ่ายต้องไม่เกิน 100%',
        ];
    }
}
