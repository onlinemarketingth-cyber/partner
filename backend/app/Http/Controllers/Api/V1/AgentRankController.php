<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\StoreAgentRankRequest;
use App\Http\Requests\Commission\UpdateAgentRankRequest;
use App\Http\Resources\AgentRankResource;
use App\Models\AgentRank;
use App\Services\Commission\AgentRankService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// ADR-011/TASK-031 config — same shape as CommissionOverrideRuleController.
class AgentRankController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(AgentRank::class, 'agent_rank');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return AgentRankResource::collection(
            AgentRank::query()->orderBy('sort_order')->paginate()
        );
    }

    public function store(StoreAgentRankRequest $request, AgentRankService $service): AgentRankResource
    {
        $rank = $service->create($request->validated(), $request->user());

        return new AgentRankResource($rank);
    }

    public function show(AgentRank $agentRank): AgentRankResource
    {
        return new AgentRankResource($agentRank);
    }

    public function update(UpdateAgentRankRequest $request, AgentRank $agentRank, AgentRankService $service): AgentRankResource
    {
        return new AgentRankResource($service->update($agentRank, $request->validated()));
    }

    public function destroy(AgentRank $agentRank): Response
    {
        $agentRank->delete();

        return response()->noContent();
    }
}
