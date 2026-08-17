<?php

namespace App\Services\Sales;

use App\Enums\TeamVisibilityLevel;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * TASK-106 / ADR-024 §4 — the ONE trustworthy answer to "who is below this
 * agent, and how much of their data may this agent see".
 *
 * Leadership is emergent from users.manager_id (ADR-024 §1) — there is no
 * leader role and no client-supplied "I am a leader" flag. Every method here
 * takes the *authenticated* User as $leader; never accept a leader id off
 * the wire.
 *
 * WHY iterative BFS rather than a recursive CTE (ADR-024 §4): tenant scoping
 * stays expressed in Eloquent where a reviewer can see it, and the traversal
 * is readable enough to audit — which matters more here than raw speed,
 * because this class is the authorisation primitive behind every /me/team
 * endpoint (TASK-107). If a tenant's tree ever makes this slow, the
 * internals can be swapped for a CTE behind the same method signatures.
 *
 * WHY subtreeIds() is not cached ACROSS requests: a stale cache here means
 * showing a leader an agent who has since been moved out of their team — a
 * disclosure bug. Correctness beats the saved queries, so there is no
 * Cache::remember() here and there must never be one.
 *
 * TASK-107 adds a REQUEST-SCOPED memo only (a plain instance property, see
 * $subtreeMemo): the /me/team endpoints call isInSubtree() and subtreeIds()
 * several times while serving a single request, and re-walking the whole
 * tree each time is pure waste. The memo dies with the instance at the end
 * of the request, so the reasoning above is untouched — the next request
 * still re-reads the live manager_id tree.
 */
class DownlineService
{
    /**
     * Circuit breakers, not business rules (so BR-7 does not apply — these
     * are not values an admin should tune).
     *
     * MAX_DEPTH guards against a manager_id cycle that the visited set below
     * somehow fails to catch, and against a pathological chain making one
     * request issue unbounded queries. 20 levels is far beyond any real
     * sales hierarchy; UserService::assertValidManager() already refuses to
     * create cycles on the write path, so anything deeper than this is bad
     * data, not a legitimate org chart.
     *
     * MAX_NODES bounds the memory/response cost of the whole-subtree rollup
     * (ADR-024 §3 — header KPIs walk the entire tree). On hitting the cap the
     * traversal stops and returns what it has: a truncated total is a
     * cosmetic problem, an unbounded query is an availability one.
     */
    public const MAX_DEPTH = 20;

    public const MAX_NODES = 2000;

    /**
     * TASK-107 — request-scoped memo of the BFS result, keyed by leader id.
     *
     * WHY this is safe where a real cache would not be: this is a plain
     * instance property on a container-resolved (non-singleton) Service, so
     * its lifetime is one instance inside one request. A single /me/team
     * request asks "is this id in my subtree?" for the parent_id / {user}
     * IDOR check AND then needs the same id list again to roll up the
     * header totals; without the memo that is two identical full walks.
     * Deliberately NOT a cross-request cache (see the class docblock).
     *
     * @var array<int, list<int>>
     */
    private array $subtreeMemo = [];

    public function __construct(private readonly TeamVisibilitySettingService $settings) {}

    /**
     * The leader's immediate reports (one level).
     *
     * @return EloquentCollection<int, User>
     */
    public function directReports(User $leader): EloquentCollection
    {
        if ($leader->company_id === null) {
            // A Super Admin has no company and therefore no downline — the
            // team screen is an Agent-side feature (ADR-024 §1). Returning
            // empty is the fail-closed answer; without this guard the
            // company_id filter below would be `where company_id is null`,
            // which silently matches every other Super Admin.
            return new EloquentCollection;
        }

        return $this->companyScoped($leader)
            ->where('manager_id', $leader->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Every user id strictly BELOW $leader in the manager_id tree — the
     * leader's own id is deliberately excluded (see isInSubtree()).
     *
     * Breadth-first, one query per level, so a lazily-expanded UI never pays
     * for depth it does not render.
     *
     * Memoized for the lifetime of THIS instance only (see $subtreeMemo) —
     * a second call inside the same request is free, a call in the next
     * request re-walks the live tree.
     *
     * @return Collection<int, int>
     */
    public function subtreeIds(User $leader): Collection
    {
        if (! array_key_exists($leader->id, $this->subtreeMemo)) {
            $this->subtreeMemo[$leader->id] = $this->walkSubtree($leader);
        }

        // A fresh Collection per call: the caller must never be handed a
        // shared mutable object that a later ->push()/->forget() elsewhere
        // in the request could silently widen.
        return collect($this->subtreeMemo[$leader->id]);
    }

    /**
     * The actual breadth-first walk. Split out of subtreeIds() purely so
     * the memo above has exactly one place to fill from.
     *
     * @return list<int>
     */
    private function walkSubtree(User $leader): array
    {
        if ($leader->company_id === null) {
            return [];
        }

        // The visited set is the real cycle guard. A manager_id cycle can
        // exist in the DB even though UserService::assertValidManager()
        // rejects one on write — a manual UPDATE, a restored backup, or a
        // future bulk-import path could introduce one. Without this set,
        // A -> B -> A makes the frontier oscillate forever and the request
        // hangs. Seeded with the leader so a child pointing back UP at the
        // leader is dropped rather than re-expanded.
        $visited = [$leader->id => true];

        $collected = [];
        $frontier = [$leader->id];

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $childIds = $this->companyScoped($leader)
                ->whereIn('manager_id', $frontier)
                ->pluck('id');

            $next = [];

            foreach ($childIds as $childId) {
                $childId = (int) $childId;

                if (isset($visited[$childId])) {
                    continue;
                }

                $visited[$childId] = true;
                $collected[] = $childId;
                $next[] = $childId;

                if (count($collected) >= self::MAX_NODES) {
                    return $collected;
                }
            }

            $frontier = $next;
        }

        return $collected;
    }

    /**
     * The authorisation primitive behind every TASK-107 endpoint: may
     * $leader look at $candidateId at all?
     *
     * Returns false for the leader themself. The /me/team endpoints have
     * their own self-scoped default (no parent_id = my own direct reports),
     * so "self" never legitimately arrives here — and per ADR-024 §3 /
     * TASK-110 case 2 an agent passing their own manager's id must 404,
     * which the strict-descendants rule gives for free.
     *
     * Cross-tenant candidates are rejected implicitly: subtreeIds() filters
     * every level by the leader's company_id, so an id from another company
     * can never appear in the result (BR-6).
     */
    public function isInSubtree(User $leader, int $candidateId): bool
    {
        if ($candidateId === $leader->id) {
            return false;
        }

        return $this->subtreeIds($leader)->contains($candidateId);
    }

    /**
     * TASK-111 (D1) — is the team-monitor feature switched ON for this
     * leader's company at all?
     *
     * A SEPARATE question from resolveLevel(), deliberately. The two were
     * conflated before: `is_enabled = false` was mapped onto CountsOnly and
     * nothing else, so the master switch only ever blocked the client
     * drill-down while the overview kept returning every subordinate's name,
     * pipeline counts AND earnings, and Home kept rendering the team menu
     * entry. Meanwhile the Admin UI told the admin the switch means "หัวหน้า
     * ทีมจะไม่เห็นหน้าทีมเลย". ag-lead ruled the admin copy is the spec: this
     * is a privacy control, and a privacy control that lies about its own
     * scope is worse than no control. So callers ask THIS when the question
     * is "does the feature exist for this tenant", and resolveLevel() only
     * when the question is "how much client data may be shown".
     *
     * False for a user with no company (a Super Admin): the team screen is an
     * Agent-side feature (ADR-024 §1) and they have no downline anyway —
     * fail-closed is the right answer, not the unconfigured-tenant default.
     */
    public function isEnabled(User $leader): bool
    {
        if ($leader->company_id === null) {
            return false;
        }

        return $this->settings->forCompany($leader->company_id)['is_enabled'] === true;
    }

    /**
     * How much of a subordinate's client data $leader may see.
     *
     * Fail-closed by construction (ADR-024 §5): a company that has never
     * configured this, or has switched the feature off, resolves to
     * CountsOnly — the least-disclosing level — rather than to whatever the
     * caller happened to ask for. Enforcement then happens in the API
     * Resource (TASK-107), never in the Vue component.
     *
     * TASK-111 (D1): the `! isEnabled()` arm below is now DEFENCE IN DEPTH,
     * not the enforcement point. Callers short-circuit on isEnabled() first
     * (TeamMonitorService::overview, MeService::home); this arm stays so that
     * a future call site which forgets to ask still cannot widen past
     * counts_only, and so the drill-down's existing counts_only 403 keeps
     * working unchanged.
     */
    public function resolveLevel(User $leader): TeamVisibilityLevel
    {
        if ($leader->company_id === null) {
            return TeamVisibilityLevel::default();
        }

        if (! $this->isEnabled($leader)) {
            return TeamVisibilityLevel::default();
        }

        $settings = $this->settings->forCompany($leader->company_id);

        // tryFrom (not from) so an unrecognised value that somehow reached
        // the column — a hand-edited row, a half-rolled-back migration —
        // degrades to the safe level instead of throwing on a hot path.
        return TeamVisibilityLevel::tryFrom($settings['client_visibility_level'])
            ?? TeamVisibilityLevel::default();
    }

    /**
     * BR-6/§5 — every level of the walk is filtered by the LEADER's own
     * company_id, explicitly, rather than leaning on TenantScope.
     *
     * TenantScope filters by the *authenticated* user, which is usually the
     * same person but is not the same guarantee: this Service is also
     * reachable from tests, console commands and (later) queued jobs where
     * there is no authenticated user at all, and there TenantScope is a
     * no-op. Dropping only TenantScope — not withoutGlobalScopes() — keeps
     * SoftDeletes active, so deactivated agents stay out of the tree.
     *
     * @return Builder<User>
     */
    private function companyScoped(User $leader): Builder
    {
        return User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('company_id', $leader->company_id);
    }
}
