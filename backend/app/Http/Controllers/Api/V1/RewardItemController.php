<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\StoreRewardItemRequest;
use App\Http\Requests\Engagement\UpdateRewardItemRequest;
use App\Http\Resources\RewardItemResource;
use App\Models\RewardItem;
use App\Services\Engagement\RewardItemService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Agent-view IA item 1.5 — reward catalog. index() shape mirrors
// BadgeController exactly: shared read (own company or platform
// default), Company Admin/Super Admin author.
class RewardItemController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(RewardItem::class, 'reward_item');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = RewardItem::query();

        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request, includePlatformWide: true);

        if (! $request->user()->isSuperAdmin()) {
            $query->where(fn ($q) => $q->where('company_id', $request->user()->company_id)->orWhereNull('company_id'));
        }

        // TASK-156 §3 — "ปิดการใช้งาน ซ่อนทุกที่" (human, 2026-08-10). The
        // Reward Center is a catalogue an Agent picks from, so a switched-off
        // item must not be offered — it would be a redemption request nobody
        // can fulfil. `is_active` was exposed as a field and never filtered;
        // the Agent Portal hid it client-side, which hid nothing.
        //
        // Company scoping above answers "whose is it"; this answers "may they
        // see it" — the two are deliberately separate, as on Announcement.
        if ($request->user()->isAgent()) {
            $query->where('is_active', true);
        }

        return RewardItemResource::collection($query->orderBy('cost_points')->get());
    }

    public function store(StoreRewardItemRequest $request, RewardItemService $service): RewardItemResource
    {
        return new RewardItemResource($service->create($request->validated(), $request->user()));
    }

    public function show(RewardItem $rewardItem): RewardItemResource
    {
        return new RewardItemResource($rewardItem);
    }

    public function update(UpdateRewardItemRequest $request, RewardItem $rewardItem, RewardItemService $service): RewardItemResource
    {
        return new RewardItemResource($service->update($rewardItem, $request->validated()));
    }

    public function destroy(RewardItem $rewardItem, RewardItemService $service): Response
    {
        $service->delete($rewardItem);

        return response()->noContent();
    }
}
