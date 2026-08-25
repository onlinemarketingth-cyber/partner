<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreClientRequest;
use App\Http\Requests\Customer\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientActivity;
use App\Models\Order;
use App\Services\Customer\ClientService;
use App\Support\CompanyScopeFilter;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * What a SINGLE client additionally arrives with.
     *
     * ── THE ORDER WAS ALWAYS IN THE RESOURCE AND NEVER IN THE PAYLOAD ──
     *
     * Human, 2026-08-22: "ผมเช็คได้ยังไงว่าลูกค้าคนนี้อยู่ในสถานะใด จ่ายเงิน
     * หรือยัง รอทำอะไร ในหน้าเดียว".
     *
     * ReferralResource has carried an `order` block — status, amount, paid_at,
     * has_slip, who verified it — since TASK-190. But it is `whenLoaded('orders')`,
     * and `orders` was not in RELATIONS, so on this endpoint the key was simply
     * ABSENT. Not null, not empty: absent. Every client the admin opened showed
     * a sales stage with no answer to "has this person paid", and nothing looked
     * broken from either side — the resource was doing exactly what it was told.
     *
     * This is the whole fix for the payment half of that question.
     *
     * ── WHY IT IS NOT IN RELATIONS ABOVE ──
     *
     * index() lists every client in the company. Orders would be a fourth
     * eager-load across the entire list to fill a block the list does not
     * render, growing the payload for nothing. The constant above is shared
     * precisely so index and show cannot drift on what they BOTH need; this
     * one is the honest statement that a detail view needs more.
     *
     * verifiedBy rides along because ReferralResource checks
     * `relationLoaded('verifiedBy')` and prints "ไม่ทราบ" otherwise — without
     * it the admin would see "confirmed by nobody" on every paid order.
     */
    private const DETAIL_RELATIONS = ['referrals.orders', 'referrals.orders.verifiedBy'];

    /**
     * Roll-ups the LIST needs, as scalar subqueries.
     *
     * ── WHY SUBQUERIES AND NOT RELATIONS (human, 2026-08-22) ──
     *
     * The client list showed a name, a phone and a status, and answering
     * "who needs attention today" meant opening customers one at a time.
     * It needs payment state and last-contact date per row.
     *
     * The obvious move — eager-load `orders` and `activities` — is the one
     * DETAIL_RELATIONS above deliberately refuses, and for a reason that
     * still holds: this endpoint returns every client in the company, and a
     * relation attaches whole objects to each of them to render four words.
     *
     * A correlated scalar subquery is a different cost entirely. Each adds
     * ONE value per row, no objects, no hydration, no N+1 — the database
     * answers while it is already reading the row. Four of them cost less
     * than one eager-loaded `orders` relation on a list of any real size.
     *
     * They are also the honest shape for the question: the row does not want
     * the orders, it wants to know whether any of them is waiting.
     */
    private function withListRollups(Builder $query): Builder
    {
        $unpaidStatuses = [OrderStatus::Pending->value, OrderStatus::AwaitingVerification->value];

        return $query->addSelect([
            // Orders the customer still owes money on.
            'unpaid_orders_count' => Order::withoutGlobalScopes()
                ->selectRaw('COUNT(*)')
                ->whereColumn('client_id', 'clients.id')
                ->whereIn('status', $unpaidStatuses),
            // ...and how much, so the row can say "รอชำระ ฿8,900" rather than
            // making somebody open the customer to find out if it matters.
            'unpaid_amount_satang' => Order::withoutGlobalScopes()
                ->selectRaw('COALESCE(SUM(amount_satang), 0)')
                ->whereColumn('client_id', 'clients.id')
                ->whereIn('status', $unpaidStatuses),
            // The only state blocked on US: a slip nobody has verified. It
            // outranks the other two in the chip, because it is the one an
            // admin can act on right now.
            'awaiting_slip_orders_count' => Order::withoutGlobalScopes()
                ->selectRaw('COUNT(*)')
                ->whereColumn('client_id', 'clients.id')
                ->where('status', OrderStatus::AwaitingVerification->value)
                ->whereNotNull('slip_path'),
            'paid_orders_count' => Order::withoutGlobalScopes()
                ->selectRaw('COUNT(*)')
                ->whereColumn('client_id', 'clients.id')
                ->where('status', OrderStatus::Paid->value),
            // Who has been left alone. `activities` is the contact log, so
            // MAX(occurred_at) is "last time anybody touched this person".
            'last_activity_at' => ClientActivity::withoutGlobalScopes()
                ->selectRaw('MAX(occurred_at)')
                ->whereColumn('client_id', 'clients.id'),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->withListRollups(Client::query()->select('clients.*'))->with(self::RELATIONS);

        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

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
        return new ClientResource($client->load([...self::RELATIONS, ...self::DETAIL_RELATIONS]));
    }

    public function update(UpdateClientRequest $request, Client $client, ClientService $service): ClientResource
    {
        // Same relation set as show(): the Admin client modal re-renders from
        // THIS response after a save, so dropping the detail relations here
        // would blank the payment block the moment anybody edited a name.
        return new ClientResource(
            $service->update($client, $request->validated())->load([...self::RELATIONS, ...self::DETAIL_RELATIONS])
        );
    }

    public function destroy(Client $client): Response
    {
        $client->delete();

        return response()->noContent();
    }
}
