<?php

namespace App\Http\Requests\Commission;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-09-16 — "บันทึกว่าโอนแล้ว" for every request the admin ticked.
 *
 * Owner, on the merged จ่ายค่าแนะนำ screen: accounting transfers in a ROUND and
 * reports back in a batch ("ได้รับข้อมูลจากฝ่ายบัญชีก่อนว่าโอนแล้วจึงมากดยืนยัน"),
 * so the screen that records it has to take a batch. Before this, recording ten
 * transfers meant ten presses and ten browser prompts, each asking for the same
 * reference the admin had just typed.
 *
 * ── ONE REFERENCE FOR THE WHOLE BATCH ──
 *
 * Not one per row. The reference identifies the TRANSFER, and a round of
 * transfers made from one bank file has one. Per-row references would be the
 * honest shape only if each row were sent separately — and if it was, the admin
 * ticks one row and presses once, which this endpoint handles identically.
 *
 * Still optional, for the same reason as the single-request version: not every
 * transfer produces a reference worth recording, and a required field here only
 * invites made-up values that look like evidence and are not.
 *
 * ── WHY THE CAP IS FIFTY ──
 *
 * Every row is settled inside ONE transaction (see
 * CommissionWithdrawalService::markManyTransferred), and settling touches
 * commission_ledger rows that nothing else may move meanwhile. Fifty is far
 * above a realistic payout round and far below the point where the lock is held
 * long enough to matter — the same cap, for the same reason, as the batch that
 * raises payouts in the first place.
 *
 * Authorisation is deliberately NOT here: it is per request row ('decide'), and
 * it is asked once per row in the Controller before the transaction opens.
 */
class MarkWithdrawalsTransferredRequest extends FormRequest
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
            'withdrawal_request_ids' => ['required', 'array', 'min:1', 'max:50'],
            'withdrawal_request_ids.*' => ['required', 'integer', 'exists:commission_withdrawal_requests,id'],
            'transfer_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'withdrawal_request_ids.required' => 'ยังไม่ได้เลือกรายการที่จะบันทึกว่าโอนแล้ว',
            'withdrawal_request_ids.max' => 'บันทึกได้ครั้งละไม่เกิน 50 รายการ — กรุณาแบ่งเป็นสองรอบ',
        ];
    }
}
