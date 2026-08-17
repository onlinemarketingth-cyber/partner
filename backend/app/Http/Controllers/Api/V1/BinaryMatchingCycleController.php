<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BinaryMatchingCycleResource;
use App\Models\BinaryMatchingCycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// ADR-011/TASK-029 — read-only cycle history, same "own or admin" shape
// as CommissionLedgerController::index (Section 5 rule 4): an Agent
// sees only their own cycles; Company Admin/Super Admin may pass
// agent_id to inspect any agent within their own company (TenantScope
// already narrows Company Admin to their own company_id regardless).
class BinaryMatchingCycleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BinaryMatchingCycle::query()->latest('period_start');

        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        } elseif ($request->filled('agent_id')) {
            $query->where('agent_id', $request->integer('agent_id'));
        }

        return BinaryMatchingCycleResource::collection($query->paginate());
    }
}
