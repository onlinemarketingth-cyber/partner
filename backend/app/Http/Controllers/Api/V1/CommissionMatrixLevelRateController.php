<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\StoreCommissionMatrixLevelRateRequest;
use App\Http\Requests\Commission\UpdateCommissionMatrixLevelRateRequest;
use App\Http\Resources\CommissionMatrixLevelRateResource;
use App\Models\CommissionMatrixLevelRate;
use App\Services\Commission\CommissionMatrixLevelRateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// ADR-011/TASK-030 config — same shape as CommissionOverrideRuleController.
class CommissionMatrixLevelRateController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CommissionMatrixLevelRate::class, 'commission_matrix_level_rate');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return CommissionMatrixLevelRateResource::collection(
            CommissionMatrixLevelRate::query()->orderBy('level')->latest('effective_from')->paginate()
        );
    }

    public function store(StoreCommissionMatrixLevelRateRequest $request, CommissionMatrixLevelRateService $service): CommissionMatrixLevelRateResource
    {
        $rate = $service->create($request->validated(), $request->user());

        return new CommissionMatrixLevelRateResource($rate);
    }

    public function show(CommissionMatrixLevelRate $commissionMatrixLevelRate): CommissionMatrixLevelRateResource
    {
        return new CommissionMatrixLevelRateResource($commissionMatrixLevelRate);
    }

    public function update(UpdateCommissionMatrixLevelRateRequest $request, CommissionMatrixLevelRate $commissionMatrixLevelRate, CommissionMatrixLevelRateService $service): CommissionMatrixLevelRateResource
    {
        $rate = $service->update($commissionMatrixLevelRate, $request->validated());

        return new CommissionMatrixLevelRateResource($rate);
    }

    public function destroy(CommissionMatrixLevelRate $commissionMatrixLevelRate): Response
    {
        $commissionMatrixLevelRate->delete();

        return response()->noContent();
    }
}
