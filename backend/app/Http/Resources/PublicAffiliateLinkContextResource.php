<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesPublicTheme;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ADR-011 Section 4 (TASK-033 gap-fill) — backs
 * GET /api/v1/public/affiliate-leads/{token}, the SECOND unauthenticated
 * read this token unlocks (the first being the /l/{token} redirect
 * itself). TASK-032 shipped the click-redirect + lead-submission routes
 * but never a "what am I looking at" endpoint — without one, the
 * TASK-033 landing page has no way to render a product name/price or a
 * product picker (when the link isn't scoped to one product) for an
 * anonymous prospect who has never logged in. This is a deliberate,
 * narrow expansion of what a valid token discloses, decided by ag-lead
 * as an implementation-completeness gap (not a BR-7 business value) —
 * see AffiliateLink model's own updated comment for the exact
 * boundary of what is/isn't exposed here.
 *
 * Never exposes: company_id, agent_id, the token itself (already known
 * to the caller), or any AffiliateLinkClick/Referral data.
 */
class PublicAffiliateLinkContextResource extends JsonResource
{
    use ResolvesPublicTheme;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'company_name' => $this->company?->name,
            // TASK-159 §3 — the link owner's theme, resolved from the token
            // so the /l/{token} landing page a prospect clicks from social
            // media paints in this company's brand, not the platform's.
            'theme' => $this->publicTheme($this->company),
            // Deliberate — an affiliate link exists specifically so a
            // prospect knows who referred them; this mirrors what any
            // real-world agent's business card/marketing link would show.
            'agent_name' => $this->agent?->name,
            'product' => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'price_satang' => $this->product->price_satang,
            ] : null,
            // Only populated when the link is NOT pre-scoped to one
            // product — the prospect then picks from this company's
            // active catalog, same set an Agent would submit a manual
            // SWS Referral against (StoreReferralRequest's product_id).
            'products' => $this->product_id ? null : Product::withoutGlobalScopes()
                ->where('company_id', $this->company_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'price_satang']),
        ];
    }
}
