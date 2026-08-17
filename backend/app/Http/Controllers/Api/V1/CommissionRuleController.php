<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCommissionRuleRequest;
use App\Http\Requests\Catalog\UpdateCommissionRuleRequest;
use App\Http\Resources\CommissionRuleResource;
use App\Models\CommissionRule;
use App\Services\Catalog\CommissionRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// BR-2 config — CommissionRulePolicy already excludes Agent from every
// action here (viewAny too), so there's no read-only Agent path to
// worry about, unlike Brand/ProductCategory/Product.
class CommissionRuleController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CommissionRule::class, 'commission_rule');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return CommissionRuleResource::collection(
            CommissionRule::query()->with(['certTier', 'product', 'productCategory'])->latest('effective_from')->paginate()
        );
    }

    public function store(StoreCommissionRuleRequest $request, CommissionRuleService $service): CommissionRuleResource
    {
        $rule = $service->create($request->validated(), $request->user());

        return new CommissionRuleResource($rule->load(['certTier', 'product', 'productCategory']));
    }

    public function show(CommissionRule $commissionRule): CommissionRuleResource
    {
        return new CommissionRuleResource($commissionRule->load(['certTier', 'product', 'productCategory']));
    }

    public function update(UpdateCommissionRuleRequest $request, CommissionRule $commissionRule, CommissionRuleService $service): CommissionRuleResource
    {
        $rule = $service->update($commissionRule, $request->validated(), $request->user());

        return new CommissionRuleResource($rule->load(['certTier', 'product', 'productCategory']));
    }

    public function destroy(CommissionRule $commissionRule): Response
    {
        $commissionRule->delete();

        return response()->noContent();
    }
}
