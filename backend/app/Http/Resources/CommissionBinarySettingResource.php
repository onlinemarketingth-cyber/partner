<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionBinarySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'matched_rate_type' => $this->matched_rate_type?->value,
            'matched_rate_value' => $this->matched_rate_value,
            'cycle_frequency' => $this->cycle_frequency?->value,
            'payout_cap_satang' => $this->payout_cap_satang,
            'carry_over_unmatched' => $this->carry_over_unmatched,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
