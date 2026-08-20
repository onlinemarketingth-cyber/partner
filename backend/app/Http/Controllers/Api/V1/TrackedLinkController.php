<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TrackedLinkGroup;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrackedLinkResource;
use App\Models\TrackedLink;
use App\Services\Link\TrackedLinkStatsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * TASK-234 — the read side of every link in the system.
 *
 * ONE ENDPOINT FOR ALL SEVEN GROUPS. Before this, an agent wanting to see
 * their own links had to visit four different screens (สินค้า, คำสั่งซื้อ,
 * ทีมของฉัน, ลิงก์พันธมิตร) and no screen anywhere showed a company its
 * links as a whole. That is the problem this exists to fix, so splitting it
 * per group would be rebuilding the problem.
 *
 * WRITES ARE NOT HERE. Links are minted by the service that owns the thing
 * being linked to — a product share is minted by ProductShareLinkService,
 * a pay link by OrderService — because each has its own rules (BR-1, team
 * leader, order state) and a generic "create a link" endpoint would have to
 * duplicate all of them. The only write this controller offers is the
 * campaign label, which belongs to the link itself and to nothing else.
 */
class TrackedLinkController extends Controller
{
    public function index(Request $request, TrackedLinkStatsService $stats): AnonymousResourceCollection|JsonResponse
    {
        $this->authorize('viewAny', TrackedLink::class);

        $validated = $request->validate([
            'group' => ['sometimes', Rule::enum(TrackedLinkGroup::class)],
            'agent_id' => ['sometimes', 'integer'],
            'summary' => ['sometimes', 'boolean'],
        ]);

        $query = $this->scopedQuery($request);

        if (isset($validated['group'])) {
            $query->where('group', $validated['group']);
        }

        // An Agent asking for someone else's links is ignored, not refused
        // — scopedQuery() has already pinned them to their own rows, so the
        // filter can only ever narrow further. Same shape as
        // CompanyScopeFilter: narrows, never widens.
        if (isset($validated['agent_id'])) {
            $query->where('created_by_user_id', $validated['agent_id']);
        }

        if ($request->boolean('summary')) {
            return response()->json(['data' => $stats->summaryByGroup($query)]);
        }

        return TrackedLinkResource::collection(
            $query->with(['createdBy', 'target'])->orderByDesc('last_clicked_at')->orderByDesc('id')->get()
        );
    }

    public function show(Request $request, TrackedLink $trackedLink, TrackedLinkStatsService $stats): JsonResponse
    {
        $this->authorize('view', $trackedLink);

        return response()->json([
            'data' => array_merge(
                (new TrackedLinkResource($trackedLink->load(['createdBy', 'target'])))->toArray($request),
                ['stats' => $stats->forLink($trackedLink)],
            ),
        ]);
    }

    /**
     * The campaign label — the only field of a link that is the link's own.
     *
     * Everything else (expiry, quota, whether it works at all) belongs to
     * the thing behind it and is edited there, so that there is exactly one
     * place where "is this product share still live" is decided.
     */
    public function update(Request $request, TrackedLink $trackedLink): TrackedLinkResource
    {
        $this->authorize('update', $trackedLink);

        $validated = $request->validate([
            'label' => ['present', 'nullable', 'string', 'max:255'],
        ]);

        $trackedLink->update(['label' => $validated['label']]);

        return new TrackedLinkResource($trackedLink->load('createdBy'));
    }

    /**
     * @return Builder<TrackedLink>
     */
    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $query = TrackedLink::query();

        if ($user->isSuperAdmin()) {
            return $request->filled('company_id')
                ? $query->where('company_id', $request->integer('company_id'))
                : $query;
        }

        // TenantScope already pins the company. An AGENT is narrowed
        // further, to the links they created: seeing a colleague's numbers
        // would tell them who is selling what and how well, which is the
        // company's business and not theirs.
        return $user->isAgent()
            ? $query->where('created_by_user_id', $user->id)
            : $query;
    }
}
