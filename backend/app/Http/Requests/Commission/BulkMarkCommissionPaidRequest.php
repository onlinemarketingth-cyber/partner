<?php

namespace App\Http\Requests\Commission;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-09-15 — "จ่ายทั้งหมดของคนนี้".
 *
 * Four fields, and the unusual one is `expected_total_satang`: the amount the
 * admin could see when they pressed the button. The server sums the rows
 * again and refuses the whole run on a disagreement — see
 * CommissionPayoutService::markAgentPaid() for why a bulk money button
 * without that check is a button that pays rows nobody approved.
 *
 * The date range mirrors the filter applied on screen so the button pays
 * exactly the set the total above it was computed from. `payment_status` is
 * deliberately NOT accepted: a payout run is always the pending rows, and an
 * endpoint that let a caller name "paid" would offer re-stamping rows that
 * were settled months ago.
 */
class BulkMarkCommissionPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The real gate is CommissionLedgerPolicy::markAgentPaid, applied in
        // the Controller once the payee has been resolved — it needs the
        // payee's company, which is not knowable from the request alone.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_id' => ['required', 'integer', 'exists:users,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            /*
             * min:1 — a payout run for zero is not a payout. The screen hides
             * the button when nothing is owed, and a request that arrives
             * anyway is a stale tab, which is precisely the case the total
             * check exists to catch.
             */
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
            'date_from' => 'วันที่เริ่มต้น',
            'date_to' => 'วันที่สิ้นสุด',
            'expected_total_satang' => 'ยอดค้างจ่ายที่แสดงบนหน้าจอ',
        ];
    }
}
