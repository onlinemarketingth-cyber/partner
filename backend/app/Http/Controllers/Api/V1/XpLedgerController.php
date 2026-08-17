<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\XpLedgerResource;
use App\Models\XpLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// BR-5 — fully read-only (no store/update/destroy routes at all; rows
// are written exclusively by GamificationService::awardXp()). Same
// "own records only for Agent" index-narrowing shape as
// CommissionLedgerController.
class XpLedgerController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(XpLedger::class, 'xp_ledger');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = XpLedger::with('user');

        if ($request->user()->isAgent()) {
            $query->where('user_id', $request->user()->id);
        }

        return XpLedgerResource::collection($query->latest()->paginate());
    }

    public function show(XpLedger $xpLedger): XpLedgerResource
    {
        return new XpLedgerResource($xpLedger->load('user'));
    }
}
