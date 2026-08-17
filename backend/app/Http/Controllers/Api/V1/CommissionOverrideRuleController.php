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

// TASK-025 / ADR-006 config — CommissionOverrideRulePolicy already
// excludes Agent from every action here (viewAny too), same as
// CommissionRuleController.
class CommissionOverrideRuleController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CommissionOverrideRule::class, 'commission_override_rule');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return CommissionOverrideRuleResource::collection(
            CommissionOverrideRule::query()->with('managerCertTier')->latest('effective_from')->paginate()
        );
    }

    public function store(StoreCommissionOverrideRuleRequest $request, CommissionOverrideRuleService $service): CommissionOverrideRuleResource
    {
        $rule = $service->create($request->validated(), $request->user());

        return new CommissionOverrideRuleResource($rule->load('managerCertTier'));
    }

    public function show(CommissionOverrideRule $commissionOverrideRule): CommissionOverrideRuleResource
    {
        return new CommissionOverrideRuleResource($commissionOverrideRule->load('managerCertTier'));
    }

    public function update(UpdateCommissionOverrideRuleRequest $request, CommissionOverrideRule $commissionOverrideRule, CommissionOverrideRuleService $service): CommissionOverrideRuleResource
    {
        $rule = $service->update($commissionOverrideRule, $request->validated());

        return new CommissionOverrideRuleResource($rule->load('managerCertTier'));
    }

    public function destroy(CommissionOverrideRule $commissionOverrideRule): Response
    {
        $commissionOverrideRule->delete();

        return response()->noContent();
    }
}
