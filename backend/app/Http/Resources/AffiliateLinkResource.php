<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'agent_id' => $this->agent_id,
            'product_id' => $this->product_id,
            'token' => $this->token,
            // ADR-011/TASK-032 — the public short link (see routes/api.php's
            // own note on why this resolves under /api/v1, not a bare /l/...).
            'public_url' => route('affiliate-links.redirect', $this->token),
            'clicks_count' => $this->whenCounted('clicks'),
            'conversions_count' => $this->whenCounted('attributedReferrals'),
            'created_at' => $this->created_at,
        ];
    }
}
