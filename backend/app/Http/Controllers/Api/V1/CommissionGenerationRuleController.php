<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\StoreCommissionGenerationRuleRequest;
use App\Http\Requests\Commission\UpdateCommissionGenerationRuleRequest;
use App\Http\Resources\CommissionGenerationRuleResource;
use App\Models\CommissionGenerationRule;
use App\Services\Commission\CommissionGenerationRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// ADR-011/TASK-031 config — same shape as CommissionMatrixLevelRateController.
class CommissionGenerationRuleController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CommissionGenerationRule::class, 'commission_generation_rule');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return CommissionGenerationRuleResource::collection(
            CommissionGenerationRule::query()->orderBy('generation_number')->latest('effective_from')->paginate()
        );
    }

    public function store(StoreCommissionGenerationRuleRequest $request, CommissionGenerationRuleService $service): CommissionGenerationRuleResource
    {
        $rule = $service->create($request->validated(), $request->user());

        return new CommissionGenerationRuleResource($rule);
    }

    public function show(CommissionGenerationRule $commissionGenerationRule): CommissionGenerationRuleResource
    {
        return new CommissionGenerationRuleResource($commissionGenerationRule);
    }

    public function update(UpdateCommissionGenerationRuleRequest $request, CommissionGenerationRule $commissionGenerationRule, CommissionGenerationRuleService $service): CommissionGenerationRuleResource
    {
        $rule = $service->update($commissionGenerationRule, $request->validated());

        return new CommissionGenerationRuleResource($rule);
    }

    public function destroy(CommissionGenerationRule $commissionGenerationRule): Response
    {
        $commissionGenerationRule->delete();

        return response()->noContent();
    }
}
