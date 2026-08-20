<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertTierResource;
use App\Models\CertTier;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Read-only. cert_tiers is global/platform-wide config (ERD-001 open
// question #2) — no company scoping, no Policy needed beyond "must be
// authenticated" (every role, including Agent, needs this to render
// Academy progress and to fill the Admin catalog's commission-rule
// form — this also closes the TODO gap flagged in TASK-002).
class CertTierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CertTier::query()->orderBy('sort_order');

        // TASK-209 — cert tiers are per-company (cert_tiers.company_id), so the
        // Super Admin's header scope applies here too. The Request parameter is
        // new: this action never needed one before.
        CompanyScopeFilter::apply($query, $request);

        return CertTierResource::collection($query->get());
    }
}
