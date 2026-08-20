<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\StoreCommissionOverrideRuleRequest;
use App\Http\Requests\Commission\UpdateCommissionOverrideRuleRequest;
use App\Http\Resources\CommissionOverrideRuleResource;
use App\Models\CommissionOverrideRule;
use App\Services\Commission\CommissionOverrideRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// CommissionOverrideRulePolicy already excludes Agent from every action
// here (viewAny too), same as CommissionRuleController.
//
// TASK-214 — index() returns EVERY row, not a page.
//
// It used to ->paginate() at Laravel's default 15 while the only client
// (the Admin rate list) reads `data` and nothing else. That was survivable
// while a company could hold at most one row per cert tier — three or four
// in practice. Now that a rate can be scoped per product, a mid-sized
// catalogue blows past 15 immediately, and the failure mode is the worst
// kind: the sixteenth rate silently vanishes from the screen while still
// being the one that pays. Same reasoning, same fix as BrandController's
// (TASK-202). These are config tables read by one admin screen — bounded
// by how many rules a human typed, not by data volume.
class CommissionOverrideRuleController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CommissionOverrideRule::class, 'commission_override_rule');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return CommissionOverrideRuleResource::collection(
            CommissionOverrideRule::query()
                ->with(['managerCertTier', 'product', 'productCategory'])
                ->latest('effective_from')
                ->get()
        );
    }

    public function store(StoreCommissionOverrideRuleRequest $request, CommissionOverrideRuleService $service): CommissionOverrideRuleResource
    {
        $rule = $service->create($request->validated(), $request->user());

        return new CommissionOverrideRuleResource($rule->load(['managerCertTier', 'product', 'productCategory']));
    }

    public function show(CommissionOverrideRule $commissionOverrideRule): CommissionOverrideRuleResource
    {
        return new CommissionOverrideRuleResource($commissionOverrideRule->load(['managerCertTier', 'product', 'productCategory']));
    }

    public function update(UpdateCommissionOverrideRuleRequest $request, CommissionOverrideRule $commissionOverrideRule, CommissionOverrideRuleService $service): CommissionOverrideRuleResource
    {
        $rule = $service->update($commissionOverrideRule, $request->validated());

        return new CommissionOverrideRuleResource($rule->load(['managerCertTier', 'product', 'productCategory']));
    }

    public function destroy(CommissionOverrideRule $commissionOverrideRule): Response
    {
        $commissionOverrideRule->delete();

        return response()->noContent();
    }
}
