<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreAgentInviteLinkRequest;
use App\Http\Resources\AgentInviteLinkResource;
use App\Models\AgentInviteLink;
use App\Services\Registration\AgentInviteLinkService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * TASK-113 / ADR-025 §3 — mint / list / revoke a team leader's recruit
 * links. Mirrors ProductShareLinkController (and AffiliateLinkController
 * before it): no update() — a link's owner, token and company are
 * immutable once minted, and "revoking" is destroy().
 *
 * Thin by Section 7: every business decision (the is_team_leader gate, the
 * soft revoke) is in AgentInviteLinkService; every visibility decision is
 * in AgentInviteLinkPolicy + TenantScope.
 */
class AgentInviteLinkController extends Controller
{
    public function __construct()
    {
        // House style (ProductShareLinkController / AffiliateLinkController):
        // index -> viewAny, store -> create, destroy -> delete on
        // AgentInviteLinkPolicy. The route parameter is snake_case
        // `agent_invite_link`; Laravel's implicit binding resolves that to
        // the camelCase method argument below.
        $this->authorizeResource(AgentInviteLink::class, 'agent_invite_link');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        // TenantScope has already limited this to the caller's company
        // (BR-6), so a Company Admin gets exactly their company's links and a
        // Super Admin gets every company's. An Agent — including a team
        // leader — is narrowed one step further to their OWN rows, because
        // TenantScope only knows about company_id; the agent_id narrowing is
        // the Controller's job here, exactly as Section 5 rule 4 splits it.
        $query = AgentInviteLink::query();
        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        }

        return AgentInviteLinkResource::collection($query->latest()->paginate());
    }

    public function store(StoreAgentInviteLinkRequest $request, AgentInviteLinkService $service): AgentInviteLinkResource
    {
        // The owner is always the caller — never $request->input('agent_id'),
        // which the Form Request deliberately does not accept.
        $link = $service->create($request->user(), $request->validated());

        return new AgentInviteLinkResource($link);
    }

    /**
     * SOFT revoke. The row survives so that every recruit's
     * `users.recruited_via_agent_link_id` keeps pointing at a real link
     * (ADR-025 §6) — see AgentInviteLinkService::revoke().
     */
    public function destroy(AgentInviteLink $agentInviteLink, AgentInviteLinkService $service): Response
    {
        $service->revoke($agentInviteLink);

        return response()->noContent();
    }
}
