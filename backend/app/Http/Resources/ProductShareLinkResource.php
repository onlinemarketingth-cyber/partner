<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TASK-056 Sprint P1 — the AUTHENTICATED view (agent's own "my product
// shares" list / mint result). public_url points at the FRONTEND page
// (/p/{token}, ProductShareView.vue), not this API — a human clicks it
// and needs a rendered page, same construction as OrderResource's
// public_pay_url.
class ProductShareLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $frontendUrl = rtrim((string) config('services.agent_portal.frontend_url'), '/');

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'agent_id' => $this->agent_id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'token' => $this->token,
            'public_url' => "{$frontendUrl}/p/{$this->token}",
            // TASK-232 — the SHORT one, and the one every share surface
            // should offer. `public_url` above stays because links minted
            // before this feature have no short code, and because anything
            // already shared still resolves through it.
            'short_url' => $this->shortUrl(),
            'view_count' => $this->view_count,
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at,
        ];
    }
}
