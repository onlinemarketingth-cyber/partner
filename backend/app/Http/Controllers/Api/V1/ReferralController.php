<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Referral\SetCoAgentRequest;
use App\Http\Requests\Referral\StoreReferralRequest;
use App\Http\Resources\PipelineStageLogResource;
use App\Http\Resources\ReferralResource;
use App\Models\Referral;
use App\Models\User;
use App\Services\Commission\CommissionSplitSettingService;
use App\Services\Referral\PipelineService;
use App\Services\Referral\ReferralService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// CLAUDE.md §2 "SWS Referral", §4.3 (pipeline state machine), BR-1
// (Basic cert gate, enforced in ReferralService against the resolved
// referring agent, not this Controller). Section 5 rule 4 — index
// narrows to the Agent's own referrals, same shape as ClientController.
// No update()/destroy() — see ReferralPolicy's comment on why.
class ReferralController extends Controller
{
    /**
     * What a Referral must arrive with, for EVERY action on this controller.
     *
     * ONE list, not six — same reasoning as ClientController::RELATIONS
     * (TASK-169 found index() and show() had silently drifted apart there).
     *
     * TASK-176 §1.4 — `orders.verifiedBy` feeds ReferralResource's `order`
     * key. Eager-loaded here precisely so the Resource never queries per row:
     * this endpoint renders a whole company's Kanban board.
     */
    private const RELATIONS = ['client', 'agent', 'coAgent', 'product', 'orders.verifiedBy'];

    public function __construct()
    {
        $this->authorizeResource(Referral::class, 'referral');
    }

    /**
     * GET /referrals/co-agent-options — TASK-026. Deliberately NOT
     * behind authorizeResource() (there's no single Referral to check
     * against) — any authenticated company member may call this, same
     * as create() on ReferralPolicy. TenantScope on the User model
     * already restricts this to the actor's own company; role=agent and
     * "exclude myself" are applied here since neither belongs in the
     * scope.
     *
     * TASK-174 §4 — refuses outright (403) when this company's co-agent
     * split is switched off. The roster only exists to feed the split
     * picker, so serving it while the split cannot be set would be handing
     * out a teammate list for no purpose (and this endpoint is itself a
     * deliberate, narrow exception to Section 5 rule 4 — it should not
     * outlive the feature it was opened for).
     */
    public function coAgentOptions(Request $request, CommissionSplitSettingService $splitSettings): JsonResponse
    {
        abort_unless($splitSettings->isEnabledForCompany($request->user()->company_id), 403);

        $agents = User::query()
            ->where('role', 'agent')
            ->where('id', '!=', $request->user()->id)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return response()->json([
            'data' => $agents->map(fn ($agent) => ['id' => $agent->id, 'name' => $agent->name])->values(),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Referral::with(self::RELATIONS);

        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        }

        return ReferralResource::collection($query->latest('submitted_at')->paginate());
    }

    public function store(StoreReferralRequest $request, ReferralService $service): ReferralResource
    {
        $referral = $service->create($request->validated(), $request->user());

        return new ReferralResource($referral->load(self::RELATIONS));
    }

    public function show(Referral $referral): ReferralResource
    {
        return new ReferralResource($referral->load(self::RELATIONS));
    }

    /** POST /referrals/{referral}/advance — see PipelineService::advance() for why no body is accepted. */
    public function advance(Request $request, Referral $referral, PipelineService $service): ReferralResource
    {
        $this->authorize('advance', $referral);

        $referral = $service->advance($referral, $request->user());

        return new ReferralResource($referral->load(self::RELATIONS));
    }

    /**
     * PATCH /referrals/{referral}/co-agent — TASK-026. See
     * ReferralService::setCoAgent() for the "editable only until
     * Complete Payment" business rule.
     */
    public function setCoAgent(SetCoAgentRequest $request, Referral $referral, ReferralService $service): ReferralResource
    {
        // validated() only includes keys present in the request body — a
        // "clear the split" call may omit them entirely rather than send
        // explicit nulls, so default both to null here.
        $data = array_merge(['co_agent_id' => null, 'split_percentage' => null], $request->validated());

        $referral = $service->setCoAgent($referral, $data, $request->user());

        return new ReferralResource($referral->load(self::RELATIONS));
    }

    /**
     * GET /referrals/{referral}/stage-logs — Section 4.3's audit trail,
     * newest first.
     *
     * Sorts by changed_at DESC, id DESC. The id tie-breaker matters:
     * changed_at only has second-level precision, so two stage changes
     * within the same second (routine in tests, and not impossible in
     * real fast-paced usage) would otherwise sort in an undefined/DB-
     * default order instead of true creation order.
     */
    public function stageLogs(Referral $referral): AnonymousResourceCollection
    {
        $this->authorize('view', $referral);

        return PipelineStageLogResource::collection(
            $referral->stageLogs()->with('changedBy')->orderByDesc('changed_at')->orderByDesc('id')->get()
        );
    }
}
