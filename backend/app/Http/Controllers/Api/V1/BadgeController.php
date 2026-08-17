<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gamification\StoreBadgeRequest;
use App\Http\Requests\Gamification\UpdateBadgeRequest;
use App\Http\Resources\BadgeResource;
use App\Models\Badge;
use App\Services\Gamification\BadgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Badge catalog. index() stays a shared read (any authenticated user —
// see BadgePolicy::viewAny) with the same nullable-company_id "own
// override or platform default" filter as GamificationRuleController.
// Phase 10 adds store/update/destroy — Admin authoring of badges,
// including condition_config for BadgeAutoAwardService — gated by
// BadgePolicy (Company Admin: own company only; Super Admin: anyone,
// including the platform default).
class BadgeController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Badge::class, 'badge');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Badge::query();

        if (! $request->user()->isSuperAdmin()) {
            $query->where(fn ($q) => $q->where('company_id', $request->user()->company_id)->orWhereNull('company_id'));
        }

        return BadgeResource::collection($query->orderBy('name')->get());
    }

    public function store(StoreBadgeRequest $request, BadgeService $service): BadgeResource
    {
        return new BadgeResource($service->create($request->validated(), $request->user()));
    }

    public function show(Badge $badge): BadgeResource
    {
        return new BadgeResource($badge);
    }

    public function update(UpdateBadgeRequest $request, Badge $badge, BadgeService $service): BadgeResource
    {
        return new BadgeResource($service->update($badge, $request->validated()));
    }

    public function destroy(Badge $badge, BadgeService $service): Response
    {
        $service->delete($badge);

        return response()->noContent();
    }
}
