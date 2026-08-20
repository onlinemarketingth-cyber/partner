<?php

namespace App\Services\Catalog;

use App\Enums\TrackedLinkGroup;
use App\Models\ProductSalesMaterial;
use App\Models\SalesMaterialShareLink;
use App\Models\User;
use App\Services\Link\TrackedLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// ADR-007 Decision 3 — signed, time-limited, revocable public link.
// Minting is authenticated + Policy-checked (ProductPolicy::view on the
// material's product, in SalesMaterialShareLinkController) — this
// Service only ever runs for an actor who already passed that check.
class SalesMaterialShareLinkService
{
    public function __construct(private readonly TrackedLinkService $trackedLinks) {}

    public function create(ProductSalesMaterial $material, int $expiresInDays, User $actor): SalesMaterialShareLink
    {
        $link = SalesMaterialShareLink::create([
            'company_id' => $material->company_id,
            'sales_material_id' => $material->id,
            'created_by_user_id' => $actor->id,
            // 32 random bytes -> 64 hex chars: unguessable (Section 5
            // rule 5's IDOR concern — this is the ONE public lookup key
            // in the whole app, so it must not be enumerable at all).
            'token' => Str::random(64),
            'expires_at' => Carbon::now()->addDays($expiresInDays),
        ]);

        // TASK-235 — the short code, carrying the SAME expiry. Without
        // copying it the short link would outlive the thing it points at:
        // the resolver would let the visitor through, and the target's own
        // isUsable() would then refuse them. Two dates that must agree, so
        // they are set together in one place.
        $this->trackedLinks->mintFor(TrackedLinkGroup::SalesMaterial, $link, $actor);
        $link->trackedLink()->withoutGlobalScopes()->first()?->update([
            'expires_at' => $link->expires_at,
        ]);

        return $link;
    }

    public function revoke(SalesMaterialShareLink $link): void
    {
        $link->update(['revoked_at' => Carbon::now()]);

        // TASK-235 — otherwise the short link keeps resolving after the
        // admin believes they have switched the material off.
        $link->trackedLink()->withoutGlobalScopes()->first()?->update(['revoked_at' => Carbon::now()]);
    }

    public function recordView(SalesMaterialShareLink $link): void
    {
        $link->increment('view_count');
    }
}
