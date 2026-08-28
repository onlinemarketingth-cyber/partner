<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CommissionWithdrawalRequest
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
            'amount_satang' => (int) $this->amount_satang,
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
