<?php

namespace App\Services\Sales;

use App\Enums\PaymentStatus;
use App\Enums\TeamVisibilityLevel;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TASK-107 / ADR-024 §2, §3, §6, §8 — the read side of the Agent Portal
 * team monitor.
 *
 * WHY this Service exists at all instead of widening ClientController /
 * ReferralController / CommissionLedgerController (ADR-024 §2): those three
 * endpoints are used by every agent every day and hard-narrow to
 * `agent_id = self`. Putting the downline query shape in here means a bug in
 * this file can only break one new read-only screen, never the daily
 * workflow of every agent in every tenant. Nothing here writes anything
 * except the PDPA audit row required by ADR-024 §8.
 *
 * Authorisation is delegated entirely to DownlineService (the single
 * authorisation primitive, TASK-106) — this class never re-derives "is this
 * id below me", and never accepts a leader identity off the wire.
 */
class TeamMonitorService
{
    /**
     * Page size for the client drill-down. A circuit breaker, not a business
     * rule (BR-7 does not apply): it bounds one response, it does not encode
     * a decision an admin should own.
     */
    public const CLIENTS_PER_PAGE = 50;

    public function __construct(
        private readonly DownlineService $downline,
        private readonly AgentSalesAggregateService $aggregate,
    ) {}

    /**
     * The team screen payload.
     *
     * Returns NULL — never a partial or empty payload — when $parentId is
     * given but is not inside the caller's own subtree. The Controller turns
     * that into a 404 (not a 403): a 403 would confirm that the id exists,
     * which is exactly the probe an IDOR attempt is making. This is the
     * feature's primary IDOR surface (ADR-024 §3).
     *
     * @return array{is_leader:bool, visibility_level:string, parent_id:?int, totals:array<string,mixed>, nodes:list<array<string,mixed>>}|null
     */
    public function overview(User $leader, ?int $parentId = null): ?array
    {
        // TASK-111 (D1) — the master switch is a REAL kill switch, checked
        // before anything is read. Previously nothing on this path looked at
        // is_enabled at all: with the switch off the overview still returned
        // every subordinate's name, client counts, pipeline stages and all
        // three satang figures, and only the drill-down was blocked.
        //
        // 200 with the non-leader shape, NOT 403: the leader has done nothing
        // wrong, the tenant may re-enable at any moment, and reusing the exact
        // payload a plain agent already gets means the frontend needs no new
        // branch. Deliberately BEFORE the parent_id/IDOR check — when the
        // feature is off there is no subtree to be inside or outside of, so
        // every request gets the same empty answer and the 403-vs-404
        // distinction cannot be used to probe the hierarchy either.
        if (! $this->downline->isEnabled($leader)) {
            return $this->emptyPayload(TeamVisibilityLevel::default(), $parentId);
        }

        $level = $this->downline->resolveLevel($leader);
        $directReports = $this->downline->directReports($leader);
        $isLeader = $directReports->isNotEmpty();

        if ($parentId !== null) {
            // THE IDOR CHECK. isInSubtree() is false for: a sibling leader's
            // node (same company, different branch), the caller's own
            // manager or any other ancestor (upward probe), the caller
            // themself, and anything in another company — subtreeIds()
            // filters every level of the walk by the leader's company_id
            // (BR-6). All of those land on the same 404.
            if (! $this->downline->isInSubtree($leader, $parentId)) {
                return null;
            }
        }

        // subtreeIds() is memoized per request (TASK-107), so asking for it
        // again after the isInSubtree() call above costs nothing.
        $subtreeIds = $this->downline->subtreeIds($leader);

        if ($subtreeIds->isEmpty()) {
            // A plain agent with no downline: HTTP 200 with an empty team,
            // never a 403 and never someone else's data (ADR-024 §3 /
            // TASK-107 acceptance criteria).
            return $this->emptyPayload($level, $parentId);
        }

        // One aggregation pass over the WHOLE subtree: the header totals
        // need all of it (ADR-024 §3 — a leader must see the true total
        // without expanding every node), and the level currently being
        // rendered is always a subset of it.
        $rollup = $this->aggregate->forAgents($subtreeIds);
        $overrides = $this->overrideSatangBySourceAgent($leader, $subtreeIds);

        $nodes = $parentId === null
            ? $directReports
            : $this->downline->directReports($this->subtreeMember($leader, $parentId));

        return [
            'is_leader' => $isLeader,
            'visibility_level' => $level->value,
            'parent_id' => $parentId,
            'totals' => $this->totals($subtreeIds, $rollup, $overrides),
            'nodes' => $this->nodes($leader, $nodes, $rollup, $overrides),
        ];
    }

    /**
     * The "feature is off / caller has no team" response.
     *
     * ONE shape for both cases on purpose (TASK-111 D1): the frontend already
     * handles `is_leader: false` by not rendering a team, so the kill switch
     * needs no new branch in HomeView or MyTeamView.
     *
     * @return array<string, mixed>
     */
    private function emptyPayload(TeamVisibilityLevel $level, ?int $parentId): array
    {
        return [
            'is_leader' => false,
            'visibility_level' => $level->value,
            // Echoed back, not resolved: it is the caller's own input, so
            // returning it discloses nothing, and the UI can still tell which
            // request this response answers.
            'parent_id' => $parentId,
            'totals' => $this->zeroTotals(),
            'nodes' => [],
        ];
    }

    /**
     * May $leader look at $agentId at all? The one question every /me/team
     * route asks before doing anything else.
     */
    public function mayView(User $leader, int $agentId): bool
    {
        return $this->downline->isInSubtree($leader, $agentId);
    }

    /**
     * TASK-111 (D3 / ADR-024 §3) — the agent ids whose IDENTITY (id + name)
     * this caller may be shown: their own subtree, plus themself.
     *
     * WHY the caller is included: a leader may legitimately share a client
     * with a subordinate, and hiding the leader's own name from their own
     * screen would be confusing without protecting anybody.
     *
     * WHY this is narrower than "everyone in the company": ADR-024 §3 answers
     * 404 when a leader asks about a sibling leader's node, precisely so one
     * branch of the org chart cannot enumerate another. A shared client's
     * referral list must not become a side channel around that boundary — see
     * TeamClientResource, which is where the narrowing is applied.
     *
     * @return list<int>
     */
    public function visibleAgentIds(User $leader): array
    {
        // subtreeIds() is memoized per request, so this is free after the
        // authorisation checks the same request already performed.
        return $this->downline->subtreeIds($leader)
            ->map(fn ($id) => (int) $id)
            ->push((int) $leader->id)
            ->unique()
            ->values()
            ->all();
    }

    public function level(User $leader): TeamVisibilityLevel
    {
        return $this->downline->resolveLevel($leader);
    }

    /**
     * The subordinate's clients, for the drill-down.
     *
     * "The subordinate's clients" = clients this agent has a referral for
     * (referrals.agent_id), deliberately NOT clients.referring_agent_id: it
     * is the same definition the node's client_count uses
     * (AgentSalesAggregateService counts DISTINCT referrals.client_id), so
     * the list length matches the number shown on the card. It also matches
     * the ?agent_id= filter the admin cockpit drill-down already uses.
     *
     * Tenant isolation: Client carries TenantScope, so the query is already
     * pinned to the authenticated leader's company; $subject additionally
     * had to pass isInSubtree() (same company by construction) before this
     * method is ever reached.
     *
     * @return LengthAwarePaginator<int, Client>
     */
    public function clientsFor(User $leader, User $subject, TeamVisibilityLevel $level): LengthAwarePaginator
    {
        $query = Client::query()
            ->whereHas('referrals', fn ($q) => $q->where('agent_id', $subject->id));

        if ($level === TeamVisibilityLevel::FullFile) {
            // The same eager-loads ClientController::index() uses, so the
            // full_file payload really is "the file as the subordinate sees
            // it" rather than a thinner lookalike.
            //
            // TASK-111 (D3): loading EVERY referral on the client is correct —
            // the client's own history is legitimate context, and dropping
            // rows would misrepresent how many deals exist on that person.
            // What is NOT correct is emitting the agent identity attached to
            // a referral owned by someone outside the caller's subtree, which
            // is why TeamClientResource is now handed visibleAgentIds() and
            // nulls the rest. The narrowing lives in the Resource, next to the
            // other level enforcement (ADR-024 §5), not in this query.
            $query->with(['referrals.product', 'referrals.agent', 'referrals.coAgent', 'category']);
        } else {
            // Names level: load ONLY this subordinate's referrals, because
            // the only thing derived from them is the client's current stage
            // under this agent. Another agent's referral on the same client
            // is not this leader's business and must not even be loaded.
            $query->with(['referrals' => fn ($q) => $q->where('agent_id', $subject->id)]);
        }

        $page = $query->latest()->paginate(self::CLIENTS_PER_PAGE);

        if ($level === TeamVisibilityLevel::FullFile) {
            $this->logFullFileDisclosure($leader, $subject, $page->items());
        }

        return $page;
    }

    /**
     * ADR-024 §8 — PDPA. At full_file a leader is reading someone else's
     * client file, including health data, so the read itself is recorded
     * (§6 "Audit Log": who, what, when).
     *
     * ONE row per request, not one per client: a list render is a single
     * disclosure event by a single actor at a single moment, and exploding
     * it into N rows would bury the log without adding any accountability
     * the client_ids array below does not already give. Nothing is written
     * when the list is empty — no disclosure happened.
     *
     * @param  array<int, Client>  $clients
     */
    private function logFullFileDisclosure(User $leader, User $subject, array $clients): void
    {
        if ($clients === []) {
            return;
        }

        AuditLog::create([
            'company_id' => $leader->company_id,
            'actor_user_id' => $leader->id,
            'action' => 'team_client_file.view',
            // The SUBJECT AGENT is the auditable record: the question this
            // log answers is "whose team data did this leader open", and a
            // single row cannot point at N clients polymorphically.
            'auditable_type' => User::class,
            'auditable_id' => $subject->id,
            'old_values' => null,
            'new_values' => [
                'subject_agent_id' => (int) $subject->id,
                'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
                'client_ids' => array_map(fn (Client $client) => (int) $client->id, $clients),
                'client_count' => count($clients),
            ],
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * BR-4 — the leader's OWN override earnings attributable to each
     * subordinate, READ from the immutable ledger. Never recomputed, and no
     * commission calculation Service is invoked anywhere in this file.
     *
     * "Attributable to that subordinate" is resolved through the SOURCE
     * REFERRAL (commission_ledger.referral_id -> referrals.agent_id), per
     * the TASK-107 spec. The join is an INNER join on purpose: ledger rows
     * with no referral (binary_match / promotion_bonus) have no source
     * agent and must not be attributed to anyone's downline.
     *
     * Paid-only, matching `commission_satang` — the other COMMISSION figure
     * on the card. TASK-179 (D1) note: `sales_satang` beside it is no longer
     * the same kind of number and must not be made to match this one. It is
     * money the CUSTOMER paid (paid orders); these two are money the company
     * DISBURSED. Two axes, deliberately, each under its own label.
     *
     * @param  Collection<int, int>  $agentIds
     * @return Collection<int, int> keyed by the SOURCE agent id
     */
    private function overrideSatangBySourceAgent(User $leader, Collection $agentIds): Collection
    {
        if ($agentIds->isEmpty()) {
            return collect();
        }

        return DB::table('commission_ledger')
            ->join('referrals', 'referrals.id', '=', 'commission_ledger.referral_id')
            ->where('commission_ledger.agent_id', $leader->id)
            ->where('commission_ledger.payment_status', PaymentStatus::Paid->value)
            ->whereIn('referrals.agent_id', $agentIds)
            ->groupBy('referrals.agent_id')
            ->selectRaw('referrals.agent_id as source_agent_id, SUM(commission_ledger.amount_satang) as override_satang')
            ->pluck('override_satang', 'source_agent_id')
            ->map(fn ($value) => (int) $value);
    }

    /**
     * Whole-subtree rollup for the header KPIs (ADR-024 §3).
     *
     * @param  Collection<int, int>  $subtreeIds
     * @param  array<int, array<string, mixed>>  $rollup
     * @param  Collection<int, int>  $overrides
     * @return array<string, mixed>
     */
    private function totals(Collection $subtreeIds, array $rollup, Collection $overrides): array
    {
        $totals = $this->zeroTotals();
        $totals['member_count'] = $subtreeIds->count();

        foreach ($subtreeIds as $agentId) {
            $row = $rollup[(int) $agentId] ?? AgentSalesAggregateService::emptyRow();

            $totals['client_count'] += $row['client_count'];
            $totals['total_deals'] += $row['total_deals'];
            $totals['closed_deals'] += $row['closed_deals'];
            $totals['closed_deals_without_order'] += $row['closed_deals_without_order'];
            $totals['sales_satang'] += $row['sales_satang'];
            $totals['commission_satang'] += $row['commission_satang'];
            $totals['my_override_satang'] += (int) ($overrides[(int) $agentId] ?? 0);

            foreach ($row['deals_by_stage'] as $stage => $count) {
                $totals['deals_by_stage'][$stage] += $count;
            }
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroTotals(): array
    {
        return [
            'member_count' => 0,
            'client_count' => 0,
            'deals_by_stage' => array_fill_keys(AgentSalesAggregateService::stageKeys(), 0),
            'total_deals' => 0,
            'closed_deals' => 0,
            // TASK-179 (D2) — closed deals with no paid order, i.e. the part
            // of `sales_satang` below that could not be counted. Carried on
            // the subtree rollup for the same reason it is carried per node:
            // a total is only honest if the screen showing it can also show
            // what was left out. Never estimated, never folded into the money.
            'closed_deals_without_order' => 0,
            // BR-3 — integer satang, every one of them. No float is ever
            // introduced into this payload (there is deliberately no
            // `conversion` percentage here, unlike the admin cockpit).
            'sales_satang' => 0,
            'commission_satang' => 0,
            'my_override_satang' => 0,
        ];
    }

    /**
     * One row per node being rendered. The User model is passed through to
     * the Resource, which owns presentation (avatar URL, cert tier shape).
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, array<string, mixed>>  $rollup
     * @param  Collection<int, int>  $overrides
     * @return list<array<string, mixed>>
     */
    private function nodes(User $leader, $users, array $rollup, Collection $overrides): array
    {
        $ids = $users->pluck('id')->map(fn ($id) => (int) $id);
        $withChildren = $this->agentIdsThatHaveReports($leader, $ids);

        return $users->map(function (User $user) use ($rollup, $overrides, $withChildren) {
            // Defensive default: a node can only be missing from $rollup if
            // the subtree walk was truncated at MAX_NODES, in which case a
            // zeroed card is better than a 500.
            $row = $rollup[(int) $user->id] ?? AgentSalesAggregateService::emptyRow();

            return array_merge($row, [
                'user' => $user,
                'has_children' => $withChildren->contains((int) $user->id),
                'my_override_satang' => (int) ($overrides[(int) $user->id] ?? 0),
            ]);
        })->values()->all();
    }

    /**
     * Which of these nodes can be expanded. One query for the whole level,
     * company-filtered for the same reason DownlineService is (BR-6), and
     * WITHOUT dropping SoftDeletes — a deactivated report must not make a
     * node look expandable.
     *
     * @param  Collection<int, int>  $agentIds
     * @return Collection<int, int>
     */
    private function agentIdsThatHaveReports(User $leader, Collection $agentIds): Collection
    {
        if ($agentIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('company_id', $leader->company_id)
            ->whereIn('manager_id', $agentIds)
            ->distinct()
            ->pluck('manager_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Load a node the caller has ALREADY been authorised for. Only ever
     * called after isInSubtree() returned true, so the company filter here
     * is belt-and-braces rather than the actual guard.
     */
    private function subtreeMember(User $leader, int $agentId): User
    {
        /** @var User $member */
        $member = User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('company_id', $leader->company_id)
            ->findOrFail($agentId);

        return $member;
    }
}
