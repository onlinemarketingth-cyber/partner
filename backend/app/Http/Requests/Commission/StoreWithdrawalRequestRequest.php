<?php

namespace App\Http\Requests\Commission;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The agent asks for an amount. Everything that makes the amount legal —
 * the company minimum, the available balance, complete payout details — is
 * checked in CommissionWithdrawalService under a lock, NOT here: each of
 * those depends on state that can change between validating and writing,
 * and a Form Request cannot hold a transaction open across that gap.
 *
 * What this class owns is the shape: a positive whole number of satang.
 */
class StoreWithdrawalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // BR-3 — satang, integer, never a float. min:1 rather than min:0
            // so a request for nothing is refused as the mistake it is.
            'amount_satang' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_satang.required' => 'กรุณาระบุจำนวนเงินที่ต้องการเบิก',
            'amount_satang.integer' => 'จำนวนเงินไม่ถูกต้อง',
            'amount_satang.min' => 'จำนวนเงินที่ขอเบิกต้องมากกว่า 0',
        ];
    }
}
