<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\StoreAgentPromotionRequest;
use App\Http\Requests\Engagement\UpdateAgentPromotionRequest;
use App\Http\Resources\AgentPromotionResource;
use App\Models\AgentPromotion;
use App\Services\Engagement\AgentPromotionService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Agent-view IA item 1.4 ("การเสนอ Promotion ให้ Agent"). index() shape:
// Company Admin/Super Admin see every promotion in-company (including
// Draft, to manage the campaign before it goes live) — same "own
// company or across companies" split as most Admin resources. An Agent
// only sees promotions that are both currently active (status +
// date window) AND targeted at them — narrowed here rather than in the
// Policy, same reasoning as ReferralController/CommissionLedgerController's
// index-level narrowing (Policy::view only gates single-resource GET).
class AgentPromotionController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(AgentPromotion::class, 'agent_promotion');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AgentPromotion::with(['product', 'targetCertTier', 'targetAgents']);

        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

        $user = $request->user();
        if ($user->isAgent()) {
            $promotions = $query->get()->filter(
                fn (AgentPromotion $promotion) => $promotion->isCurrentlyActive() && $promotion->appliesToAgent($user)
            )->values();

            return AgentPromotionResource::collection($promotions);
        }

        return AgentPromotionResource::collection($query->latest()->get());
    }

    public function store(StoreAgentPromotionRequest $request, AgentPromotionService $service): AgentPromotionResource
    {
        $promotion = $service->create($request->safe()->except('target_agent_ids'), $request->user(), $request->input('target_agent_ids', []));

        return new AgentPromotionResource($promotion);
    }

    public function show(Request $request, AgentPromotion $agentPromotion): AgentPromotionResource
    {
        /*
         * TASK-156 — the two predicates index() already applies, applied here.
         *
         * AgentPromotionPolicy::view() checks appliesToAgent() but NOT
         * isCurrentlyActive(), so an Agent who knew an id could read a
         * promotion scheduled to start next quarter or one that ended months
         * ago — including its bonus amount and targets. index() filters on
         * BOTH, which is what makes the gap a leak rather than a design.
         *
         * 404, not 403, consistent with CLAUDE.md §5.5: an unannounced
         * promotion's existence is itself the thing being withheld.
         *
         * These are the model's own predicates, not a re-implementation, so
         * this route and the list cannot answer differently.
         */
        $user = $request->user();

        if ($user?->isAgent()) {
            abort_unless(
                $agentPromotion->isCurrentlyActive() && $agentPromotion->appliesToAgent($user),
                404,
            );
        }

        return new AgentPromotionResource($agentPromotion->load(['product', 'targetCertTier', 'targetAgents']));
    }

    public function update(UpdateAgentPromotionRequest $request, AgentPromotion $agentPromotion, AgentPromotionService $service): AgentPromotionResource
    {
        $targetAgentIds = $request->has('target_agent_ids') ? $request->input('target_agent_ids') : null;
        $promotion = $service->update($agentPromotion, $request->safe()->except('target_agent_ids'), $targetAgentIds);

        return new AgentPromotionResource($promotion);
    }

    public function destroy(AgentPromotion $agentPromotion, AgentPromotionService $service): Response
    {
        $service->delete($agentPromotion);

        return response()->noContent();
    }
}
