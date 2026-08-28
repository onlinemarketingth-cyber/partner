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
        ];
    }
}
