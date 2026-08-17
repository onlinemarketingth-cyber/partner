<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreClientRequest;
use App\Http\Requests\Customer\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\Customer\ClientService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// CLAUDE.md Section 5 rule 4 — index narrows to the Agent's own
// referred clients; Company Admin/Super Admin see the full company (or
// cross-company for Super Admin, via TenantScope's bypass).
// authorizeResource() still runs for view/create/update/delete —
// index has its own explicit query-level narrowing below since "which
// rows show up" isn't something a Policy's viewAny/view alone can do.
class ClientController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Client::class, 'client');
    }

    /**
     * What a Client must arrive with, for BOTH index and show.
     *
     * referrals+product drives the "products of interest" + status display;
     * referrals.agent/coAgent (TASK-049) show WHICH agent is selling each
     * product — a client can have several referrals for the same product by
     * different agents (A couldn't close, B is now trying), and the file must
     * surface each seller prominently.
     *
     * ONE list, not two. TASK-169 Phase 2 found index() and show() had
     * drifted: show() omitted agent/coAgent, so re-fetching a single client
     * (which the drawer does after every edit) silently dropped the TASK-026
     * co-agent line until a full list reload. A shared constant is the only
     * version of this that cannot drift again.
     */
    private const RELATIONS = ['referrals.product', 'referrals.agent', 'referrals.coAgent', 'category'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Client::query()->with(self::RELATIONS);

        if ($request->user()->isAgent()) {
            $query->where('referring_agent_id', $request->user()->id);
        }

        // TASK-049 — search. `q` is a free-text LIKE across name/phone/
        // email (partial match). `national_id` is EXACT-only: the column
        // is encrypted and therefore unsearchable directly, so we match on
        // the deterministic blind index (Client::hashNationalId) — a
        // caller must supply the full digits to get a hit, partial national
        // IDs can't be searched (documented trade-off of encrypt + search).
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }
        // TASK-050 — drill-down from the "ทีมขาย" cockpit: show only clients
        // this agent is selling to (has a referral for). Uses referrals.agent_id
        // (the seller), so a client worked by multiple agents shows up under
        // each of them. Admin-facing filter; the Agent-role narrowing above
        // still applies on top, so an Agent can't use it to widen past their
        // own referred clients.
        if (($agentId = $request->integer('agent_id')) > 0) {
            $query->whereHas('referrals', fn ($q) => $q->where('agent_id', $agentId));
        }
        // TASK-056 Sprint P2 — filter by client segmentation (BR-7 config).
        if (($categoryId = $request->integer('client_category_id')) > 0) {
            $query->where('client_category_id', $categoryId);
        }
        if (($nationalId = trim((string) $request->query('national_id', ''))) !== '') {
            // A non-digit (or empty-after-normalization) value hashes to
            // null; guard against Eloquent rewriting where(col, null) into
            // whereNull — that would wrongly return every client that has
            // NO national ID. Fall back to a sentinel that can never equal
            // a real 64-hex-char HMAC, i.e. zero results.
            $query->where('national_id_hash', Client::hashNationalId($nationalId) ?? 'no-match');
        }

        return ClientResource::collection($query->latest()->paginate());
    }

    public function store(StoreClientRequest $request, ClientService $service): ClientResource
    {
        $client = $service->create($request->validated(), $request->user());

        return new ClientResource($client->load(self::RELATIONS));
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client->load(self::RELATIONS));
    }

    public function update(UpdateClientRequest $request, Client $client, ClientService $service): ClientResource
    {
        return new ClientResource($service->update($client, $request->validated())->load(self::RELATIONS));
    }

    public function destroy(Client $client): Response
    {
        $client->delete();

        return response()->noContent();
    }
}
