<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// TASK-056 Sprint P1 — minting is authenticated + Policy-checked
// (ProductShareLinkController::store()); only the resulting token is ever
// unauthenticated (consumed via GET /public/product-shares/{token}).
class ProductShareLinkService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): ProductShareLink
    {
        $agentId = $actor->isAgent() ? $actor->id : $data['agent_id'];
        $agent = User::findOrFail($agentId);

        // BR-1 (Access Gate) — a product-share link is a selling channel
        // like SWS Referral/Affiliate Link; same gate reused, not invented.
        if (! $agent->hasPassedCertTier('basic')) {
            throw ValidationException::withMessages([
                'agent_id' => 'BR-1: this agent has not passed the Basic certification yet, so no product-share link can be minted for them.',
            ]);
        }

        $product = Product::findOrFail($data['product_id']);

        // Reuse an existing, still-usable link for the same agent+product
        // instead of minting a duplicate every time the Agent taps "share"
        // on the same product — keeps view_count meaningful as one running
        // total per agent+product, and avoids link-list clutter.
        $existing = ProductShareLink::where('agent_id', $agentId)
            ->where('product_id', $product->id)
            ->whereNull('revoked_at')
            ->first();
        if ($existing) {
            return $existing;
        }

        return ProductShareLink::create([
            'company_id' => $actor->company_id,
            'agent_id' => $agentId,
            'product_id' => $product->id,
            // 64-char cryptographically random token — unguessable
            // (Section 5 rule 5), same convention as every other public-
            // token table in this app.
            'token' => Str::random(64),
        ]);
    }
}
