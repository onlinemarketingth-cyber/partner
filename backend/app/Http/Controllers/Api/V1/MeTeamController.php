<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TeamVisibilityLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamClientResource;
use App\Http\Resources\TeamNodeResource;
use App\Models\User;
use App\Services\Sales\TeamMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TASK-107 / ADR-024 — the Agent Portal team monitor. READ ONLY by
 * construction: every route bound to this controller is a GET, and there is
 * no store/update/destroy here or anywhere under /me/team (ADR-024 §7).
 * Advancing a subordinate's pipeline stage, editing their client, granting
 * certification and marking commission paid all stay outside this feature —
 * CommissionLedgerPolicy::markPaid in particular remains Company/Super Admin
 * only, so a leader can never settle their team's payout.
 *
 * Same self-scoped construction as MeController: the caller is always
 * $request->user(), never a {user} identifying WHO is asking. The one route
 * parameter that does exist ({user} on the drill-down) identifies who is
 * being LOOKED AT, and is authorised against the caller's own subtree before
 * anything is read.
 *
 * Thin by design (§7): both actions do authorise → delegate → wrap in a
 * Resource, and hold no business logic.
 */
class MeTeamController extends Controller
{
    /**
     * GET /api/v1/me/team[?parent_id=]
     */
    public function index(Request $request, TeamMonitorService $service): JsonResponse
    {
        // Validated rather than blindly cast: a non-integer parent_id is a
        // malformed request (422), not "no parent" — silently treating
        // ?parent_id=abc as the root would answer a question nobody asked.
        $validated = $request->validate([
            'parent_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Cast explicitly: a query string always arrives as a string, and
        // the id is compared against integer user ids inside the subtree
        // check — a loose comparison is not something an IDOR guard should
        // depend on.
        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;

        $payload = $service->overview($request->user(), $parentId);

        // 404, not 403: parent_id was outside the caller's own subtree
        // (a sibling leader's branch, an ancestor, another tenant, or an id
        // that does not exist). All four must be indistinguishable, or the
        // response itself becomes an existence oracle for an IDOR probe.
        abort_if($payload === null, 404);

        return response()->json([
            'data' => [
                'is_leader' => $payload['is_leader'],
                'visibility_level' => $payload['visibility_level'],
                'parent_id' => $payload['parent_id'],
                'totals' => $payload['totals'],
                'nodes' => TeamNodeResource::collection($payload['nodes']),
            ],
        ]);
    }

    /**
     * GET /api/v1/me/team/{user}/clients
     */
    public function clients(Request $request, User $user, TeamMonitorService $service): JsonResponse
    {
        $leader = $request->user();

        // Order matters. The subtree check runs FIRST so that a leader in a
        // counts_only company cannot use the 403-vs-404 difference to probe
        // which agent ids exist below other people.
        abort_unless($service->mayView($leader, (int) $user->id), 404);

        $level = $service->level($leader);

        // ADR-024 §5 — at counts_only the endpoint effectively does not
        // exist for this tenant. Also the fail-closed answer for a company
        // that has never configured the feature, or has switched it off:
        // TASK-111 (D1) makes is_enabled a real kill switch on the overview,
        // and this endpoint keeps failing through DownlineService::
        // resolveLevel()'s CountsOnly fallback — deliberately left as the
        // enforcement point here so the disabled case and the counts_only
        // case stay literally the same 403 with no second code path.
        abort_if($level === TeamVisibilityLevel::CountsOnly, 403);

        $page = $service->clientsFor($leader, $user, $level);

        return response()->json([
            // TASK-111 (D3) — the Resource is told which agent identities this
            // caller is entitled to see, so a client SHARED with an agent
            // outside the caller's subtree cannot name that agent.
            'data' => TeamClientResource::forLevel($page->items(), $level, $service->visibleAgentIds($leader)),
            'meta' => [
                'agent_id' => (int) $user->id,
                // Echoed so the UI renders the right card without guessing
                // from which keys happen to be present.
                'visibility_level' => $level->value,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
