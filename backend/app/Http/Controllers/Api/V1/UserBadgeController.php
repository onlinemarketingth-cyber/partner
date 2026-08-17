<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gamification\StoreUserBadgeRequest;
use App\Http\Resources\UserBadgeResource;
use App\Models\UserBadge;
use App\Services\Gamification\UserBadgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// index() — Agent-scoped "own earned badges" (same shape as
// CommissionLedgerController/XpLedgerController). store() is the one
// allowed write: a manual award by Company Admin/Super Admin, gated by
// UserBadgePolicy::award() inside StoreUserBadgeRequest.
class UserBadgeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', UserBadge::class);

        $query = UserBadge::with(['user', 'badge']);

        if ($request->user()->isAgent()) {
            $query->where('user_id', $request->user()->id);
        }

        return UserBadgeResource::collection($query->latest()->get());
    }

    public function store(StoreUserBadgeRequest $request, UserBadgeService $service): UserBadgeResource
    {
        $userBadge = $service->award($request->validated('user_id'), $request->validated('badge_id'));

        return new UserBadgeResource($userBadge->load(['user', 'badge']));
    }
}
