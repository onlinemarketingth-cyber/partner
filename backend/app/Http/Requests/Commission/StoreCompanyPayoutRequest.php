<?php

namespace App\Http\Requests\Commission;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-09-15 — an admin raises a payout for an agent ("ตั้งจ่าย").
 *
 * Two fields, and the second one is the unusual one.
 *
 * `expected_total_satang` is the balance the admin could SEE when they
 * pressed the button. The server recomputes the agent's available balance and
 * refuses the whole thing on a disagreement — because the gap between the
 * screen loading and the press is a gap in which a sale can complete and
 * write a new commission row, and a payout button without that check would
 * quietly raise a larger payout than the person authorised.
 *
 * There is deliberately NO `amount_satang`. A company payout settles what the
 * agent is owed, in full; a partial amount is the shape an AGENT's request
 * has, because only they know why they want less than everything. Offering
 * both here would put two numbers on one screen whose difference nobody could
 * explain.
 *
 * The real gate is CommissionWithdrawalRequestPolicy::raise, applied in the
 * Controller once the payee has been resolved — it needs the payee's company,
 * which is not knowable from this request alone.
 */
class StoreCompanyPayoutRequest extends FormRequest
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
            'agent_id' => ['required', 'integer', 'exists:users,id'],
            // min:1 — a payout for zero is not a payout. The screen hides the
            // button when nothing is owed, so a request that arrives anyway is
            // a stale tab, which is exactly what the check below catches.
            'expected_total_satang' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'agent_id' => 'ตัวแทน',
            'expected_total_satang' => 'ยอดที่แสดงบนหน้าจอ',
        ];
    }
}
