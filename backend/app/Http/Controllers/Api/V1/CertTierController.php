<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertTierResource;
use App\Models\CertTier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Read-only. cert_tiers is global/platform-wide config (ERD-001 open
// question #2) — no company scoping, no Policy needed beyond "must be
// authenticated" (every role, including Agent, needs this to render
// Academy progress and to fill the Admin catalog's commission-rule
// form — this also closes the TODO gap flagged in TASK-002).
class CertTierController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CertTierResource::collection(CertTier::query()->orderBy('sort_order')->get());
    }
}
