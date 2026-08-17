<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BinaryMatchingCycleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'left_volume_satang' => $this->left_volume_satang,
            'right_volume_satang' => $this->right_volume_satang,
            'matched_volume_satang' => $this->matched_volume_satang,
            'unmatched_carried_satang' => $this->unmatched_carried_satang,
            // null when the cycle matched zero volume (no ledger row
            // created — BR-4 "never a $0 row" precedent, see
            // BinaryCommissionService::processAgentCycle()).
            'commission_ledger_id' => $this->commission_ledger_id,
            'created_at' => $this->created_at,
        ];
    }
}
