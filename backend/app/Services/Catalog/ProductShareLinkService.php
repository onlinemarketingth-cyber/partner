<?php

namespace App\Services\Catalog;

use App\Enums\TrackedLinkGroup;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\User;
use App\Services\Link\TrackedLinkService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// TASK-056 Sprint P1 — minting is authenticated + Policy-checked
// (ProductShareLinkController::store()); only the resulting token is ever
// unauthenticated (consumed via GET /public/product-shares/{token}).
class ProductShareLinkService
{
    public function __construct(private readonly TrackedLinkService $trackedLinks) {}

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
            /*
             * TASK-232 (UAT, 2026-08-20) — MINT ON THE REUSE PATH TOO.
             *
             * Found by pressing "แชร์" and getting the 64-character URL
             * back. This method is idempotent per agent+product, so for
             * every share link that already existed — which is all of them
             * on any live database — it returned here BEFORE reaching the
             * mint below. The short code was only ever created for a
             * product an agent had never shared before, which is the rare
             * case, and every existing link would have stayed long forever
             * with nothing to explain why.
             *
             * `mintFor` is itself idempotent, so this hands back the same
             * short link on every subsequent press rather than minting a
             * second one.
             *
             * This is not backfilling: the long URL keeps working exactly
             * as it did, and nobody holding one is affected. It simply
             * means an agent who presses share again — the moment they are
             * about to hand the link out — gets the short form to hand out.
             */
            $this->trackedLinks->mintFor(TrackedLinkGroup::ProductShare, $existing, $actor);

            return $existing;
        }

        $link = ProductShareLink::create([
            'company_id' => $actor->company_id,
            'agent_id' => $agentId,
            'product_id' => $product->id,
            // 64-char cryptographically random token — unguessable
            // (Section 5 rule 5), same convention as every other public-
            // token table in this app.
            //
            // TASK-232 kept this even though the short code below is what
            // gets shared now: it is what every link already out in the
            // world resolves by, and the public resolver still accepts it.
            'token' => Str::random(64),
        ]);

        // TASK-232 — mint the short code in the same breath. Doing it here
        // rather than lazily on first read means every link has one from
        // the moment it exists, so no screen ever has to decide what to
        // show for a link that has not been "activated" yet.
        $this->trackedLinks->mintFor(TrackedLinkGroup::ProductShare, $link, $actor);

        // NOT `$link->fresh()`. The controller answers 201 vs 200 from
        // `wasRecentlyCreated`, which a re-fetched instance does not carry —
        // returning a fresh copy silently turned every mint into a 200. And
        // it was never needed: `shortUrl()` looks the tracked link up on
        // demand, so this instance is already able to render it.
        return $link;
    }
}
