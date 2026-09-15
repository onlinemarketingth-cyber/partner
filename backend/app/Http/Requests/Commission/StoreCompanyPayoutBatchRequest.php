<?php

namespace App\Http\Requests\Commission;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-09-15 — "ตั้งจ่าย" for everybody the admin ticked, in one press.
 *
 * Owner chose แบบ C for the payout screen: a table with checkboxes and one
 * button carrying the running total. This is what that button sends.
 *
 * ── WHY EACH ROW CARRIES ITS OWN EXPECTED TOTAL ──
 *
 * The same reason the single-payee request does: the amount actually paid is
 * the server's, and the number in the body is only what the admin was SHOWN,
 * so the server can refuse a press made against a figure that has since moved.
 * One grand total for the batch would not do — it would let two payees' errors
 * cancel out (one sale completed, one refund posted) and pay both at figures
 * nobody authorised.
 *
 * ── WHY THE CAP IS FIFTY ──
 *
 * Every payout in a batch is written inside ONE transaction that holds a row
 * lock per payee (CommissionWithdrawalService::payOutMany), and nothing else
 * touching those agents can proceed while it is open. Fifty is far above a
 * realistic payout round and far below the point at which the lock is held
 * long enough to matter. A company that genuinely needs more presses twice.
 *
 * Authorisation is deliberately NOT here. It is per-payee — an admin may
 * raise for their own company's people and a Super Admin for anyone's — so it
 * is asked once per row in the Controller, against the resolved User, exactly
 * as the single-payee endpoint asks it.
 */
class StoreCompanyPayoutBatchRequest extends FormRequest
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
            'payees' => ['required', 'array', 'min:1', 'max:50'],
            'payees.*.agent_id' => ['required', 'integer', 'exists:users,id'],
            /*
             * min:0, not min:1 — unlike the single-payee endpoint.
             *
             * A zero here is not "pay nothing"; it is an admin whose screen
             * says zero pressing the button, and the right answer to that is
             * the service's own refusal naming both figures, not a validation
             * error about a field the admin never typed. The service refuses
             * any amount at or below zero anyway.
             */
            'payees.*.expected_total_satang' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payees.required' => 'ยังไม่ได้เลือกใครสักคน',
            'payees.max' => 'ตั้งจ่ายได้ครั้งละไม่เกิน 50 คน — กรุณาแบ่งเป็นสองรอบ',
        ];
    }
}
