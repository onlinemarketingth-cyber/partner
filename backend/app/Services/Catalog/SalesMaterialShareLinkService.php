<?php

namespace App\Services\Catalog;

use App\Models\ProductSalesMaterial;
use App\Models\SalesMaterialShareLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// ADR-007 Decision 3 — signed, time-limited, revocable public link.
// Minting is authenticated + Policy-checked (ProductPolicy::view on the
// material's product, in SalesMaterialShareLinkController) — this
// Service only ever runs for an actor who already passed that check.
class SalesMaterialShareLinkService
{
    public function create(ProductSalesMaterial $material, int $expiresInDays, User $actor): SalesMaterialShareLink
    {
        return SalesMaterialShareLink::create([
            'company_id' => $material->company_id,
            'sales_material_id' => $material->id,
            'created_by_user_id' => $actor->id,
            // 32 random bytes -> 64 hex chars: unguessable (Section 5
            // rule 5's IDOR concern — this is the ONE public lookup key
            // in the whole app, so it must not be enumerable at all).
            'token' => Str::random(64),
            'expires_at' => Carbon::now()->addDays($expiresInDays),
        ]);
    }

    public function revoke(SalesMaterialShareLink $link): void
    {
        $link->update(['revoked_at' => Carbon::now()]);
    }

    public function recordView(SalesMaterialShareLink $link): void
    {
        $link->increment('view_count');
    }
}
