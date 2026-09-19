<?php

namespace App\Http\Resources;

use App\Enums\WithdrawalSource;
use App\Models\CommissionWithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionWithdrawalRequest
 */
class CommissionWithdrawalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'agent_name' => $this->whenLoaded('agent', fn () => $this->agent?->name),
            // GROSS — what the agent earned, and what this payout draws from
            // the ledger. Never reduced by withholding.
            'amount_satang' => (int) $this->amount_satang,
            /*
             * 2026-09-19 — ภาษีหัก ณ ที่จ่าย, three figures because they
             * answer three questions and two of them cannot be recovered
             * from the third: what was earned, what was withheld in the
             * agent's name, and what the bank actually sends.
             *
             * `net_transfer_satang` comes off the model's accessor, never off
             * the column: a row written before withholding existed has NULL
             * there and its true net is its gross. THIS is the number the
             * payout screen must show an admin to transfer, and the number an
             * agent should be told to expect.
             */
            'wht_rate_at_time' => $this->wht_rate_at_time === null ? null : (int) $this->wht_rate_at_time,
            'wht_satang' => (int) ($this->wht_satang ?? 0),
            'net_transfer_satang' => $this->resource->netTransferSatang(),
            /*
             * 2026-09-15 — which of the two doors this came through.
             *
             * Sent on every row because the queue mixes them: a reviewer
             * looking at "รอตรวจสอบ" is looking at work an agent raised, and
             * one looking at "รอโอน" may be looking at either. Without this
             * the two are indistinguishable on screen, which is the exact
             * confusion merging them into one table could otherwise create.
             *
             * Coalesced rather than assumed non-null: rows written before the
             * column existed carry the migration's default, but a model
             * hydrated in a test or a half-applied deploy may not, and a null
             * here would throw on ->value at render time rather than degrade.
             */
            'source' => ($this->source ?? WithdrawalSource::AgentRequest)->value,
            'source_label' => ($this->source ?? WithdrawalSource::AgentRequest)->label(),
            'status' => $this->status->value,
            // The Thai label lives on the enum, so the agent portal and the
            // admin console cannot show two different words for one state.
            'status_label' => $this->status->label(),
            'rejection_reason' => $this->rejection_reason,
            'decided_at' => $this->decided_at,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'transferred_at' => $this->transferred_at,
            'transfer_reference' => $this->transfer_reference,
            // MASKED, always — including for the agent themselves. Unlike
            // UserResource's own bank field there is no "owner sees the full
            // number" case here: this is a historical snapshot for
            // recognising an account, and nothing on either screen needs the
            // digits back.
            'bank_name' => $this->bank_name,
            'bank_account_number_masked' => $this->maskedBankAccountNumber(),
            'bank_account_holder_name' => $this->bank_account_holder_name,
            // How many ledger rows this payout draws on — enough for a
            // "3 รายการ" summary without shipping the whole allocation.
            'item_count' => $this->whenLoaded('items', fn () => $this->items->count()),
            'created_at' => $this->created_at,
        ];
    }
}
