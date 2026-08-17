<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesMaterialShareLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The public-facing URL is built from the token here (once,
            // at creation-response time) — frontend never has to know
            // the route shape.
            'share_url' => url("/api/v1/share/sales-materials/{$this->token}"),
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            'view_count' => $this->view_count,
            'created_by' => $this->whenLoaded('createdBy', fn () => ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]),
            'created_at' => $this->created_at,
        ];
    }
}
