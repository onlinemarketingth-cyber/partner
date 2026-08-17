<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateAttributionSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'attribution_window_days' => $this->attribution_window_days,
            'new_vs_returning_rate_differential_enabled' => $this->new_vs_returning_rate_differential_enabled,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
