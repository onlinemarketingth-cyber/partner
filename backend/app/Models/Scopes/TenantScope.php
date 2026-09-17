<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that auto-filters every query by the authenticated user's
 * company_id — per CLAUDE.md Section 5, rule 2 (BR-6, highest priority).
 *
 * Apply via `protected static function booted() { static::addGlobalScope(new TenantScope); }`
 * on every model whose table has a `company_id` column.
 *
 * Visibility rules (Section 5, rule 4):
 *   - Agent: company_id = own company AND (if applicable) agent_id = self
 *     — the agent_id narrowing is enforced by Policies, not this scope.
 *   - Company Admin: all records within own company_id.
 *   - Super Admin: no company_id filter (sees across companies).
 *
 * Wired against the real schema as of TASK-001: `users.company_id` +
 * `users.role` (App\Enums\UserRole). `method_exists` guards are kept
 * defensively since this scope may be reused on models whose "current
 * actor" isn't necessarily an App\Models\User in the future.
 */
class TenantScope implements Scope
{
    /**
     * Re-entrancy guard. auth()->user() resolves the authenticated user by
     * querying the User model itself — which also carries this same global
     * scope. Without this flag, resolving the user re-enters apply(), which
     * calls auth()->user() again, which resolves the user again... an
     * infinite loop that exhausts PHP's memory limit inside Eloquent's
     * Builder (confirmed in local testing: GET /api/v1/me crashed the dev
     * server with "Allowed memory size ... exhausted" once a session/token
     * user actually needed to be looked up — login itself didn't trigger it
     * because there's no session user yet to resolve at that point).
     *
     * The nested query (the one resolving the current auth user) runs
     * unscoped, which is correct: you can't tenant-filter the query whose
     * whole purpose is to find out which tenant the current user belongs
     * to. The outer, real query still gets scoped normally once auth()
     * ->user() returns.
     */
    protected static bool $resolvingAuthUser = false;

    /**
     * Resolve the acting user behind the re-entrancy guard above.
     *
     * Extracted from apply() (TASK-217) so SharedOrTenantScope can reuse
     * the guard rather than paste a second copy of it — two copies of a
     * static re-entrancy flag is how one of them stops being set.
     */
    protected static function actor(): ?object
    {
        if (self::$resolvingAuthUser) {
            return null;
        }

        self::$resolvingAuthUser = true;
        try {
            return auth()->user();
        } finally {
            self::$resolvingAuthUser = false;
        }
    }

    /**
     * True when this scope must not narrow anything: no authenticated
     * user, a re-entrant lookup, or a Super Admin (Section 5, rule 4).
     */
    protected static function seesEverything(?object $user): bool
    {
        if (! $user) {
            return true;
        }

        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    public function apply(Builder $builder, Model $model): void
    {
        $user = self::actor();

        if (self::seesEverything($user)) {
            return;
        }

        if (isset($user->company_id)) {
            $builder->where($model->getTable().'.company_id', $user->company_id);

            return;
        }

        /*
         * 2026-09-17 — FAIL CLOSED. This branch used to do nothing.
         *
         * Read the old code carefully and it says: an authenticated user who
         * is not a Super Admin and has no company_id is not filtered at all —
         * which is not "sees their own tenant", it is "sees EVERY tenant".
         * BR-6 inverted, by the one shape nobody writes a test for.
         *
         * It was unreachable-ish while every non-super-admin row carried a
         * company_id, and RestrictScopedRole covered the one role that might
         * not. Neither is a guarantee: the first is a data invariant with no
         * constraint behind it, and the second is a path allowlist that has
         * nothing to say about a query. Then Company Partner arrived — a role
         * that by design has company_id NULL and supplier_id instead — and
         * the shape stopped being hypothetical.
         *
         * So: no tenant, no rows. A partner reaching a tenant-scoped model
         * gets an empty result rather than the whole platform, and if that is
         * ever wrong the symptom is a screen that shows nothing, which
         * somebody reports, rather than a screen that shows another company's
         * customers, which nobody does.
         *
         * Supplier-side queries are unaffected: every one of them runs
         * `withoutGlobalScopes()` and filters on `supplier_id` explicitly,
         * precisely because their rows belong to two companies at once and no
         * global scope could ever have been right for them.
         */
        $builder->whereRaw('1 = 0');
    }
}
