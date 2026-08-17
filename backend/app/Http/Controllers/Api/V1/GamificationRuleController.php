<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gamification\StoreGamificationRuleRequest;
use App\Http\Requests\Gamification\UpdateGamificationRuleRequest;
use App\Http\Resources\GamificationRuleResource;
use App\Models\GamificationRule;
use App\Services\Gamification\GamificationRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// BR-5 config. GamificationRule is deliberately NOT TenantScope'd (see
// its model docblock — company_id nullable = platform default), so
// index() has to filter manually: own company's overrides + the
// platform default, for anyone who isn't Super Admin.
class GamificationRuleController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(GamificationRule::class, 'gamification_rule');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = GamificationRule::query();

        if (! $request->user()->isSuperAdmin()) {
            $query->where(fn ($q) => $q->where('company_id', $request->user()->company_id)->orWhereNull('company_id'));
        }

        return GamificationRuleResource::collection($query->latest()->paginate());
    }

    public function store(StoreGamificationRuleRequest $request, GamificationRuleService $service): GamificationRuleResource
    {
        return new GamificationRuleResource($service->create($request->validated(), $request->user()));
    }

    public function show(GamificationRule $gamificationRule): GamificationRuleResource
    {
        return new GamificationRuleResource($gamificationRule);
    }

    public function update(UpdateGamificationRuleRequest $request, GamificationRule $gamificationRule, GamificationRuleService $service): GamificationRuleResource
    {
        return new GamificationRuleResource($service->update($gamificationRule, $request->validated()));
    }

    public function destroy(GamificationRule $gamificationRule): Response
    {
        $gamificationRule->delete();

        return response()->noContent();
    }
}
