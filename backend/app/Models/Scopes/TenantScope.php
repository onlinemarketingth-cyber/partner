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
    private static bool $resolvingAuthUser = false;

    public function apply(Builder $builder, Model $model): void
    {
        if (self::$resolvingAuthUser) {
            return;
        }

        self::$resolvingAuthUser = true;
        try {
            $user = auth()->user();
        } finally {
            self::$resolvingAuthUser = false;
        }

        if (! $user) {
            return;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }

        if (isset($user->company_id)) {
            $builder->where($model->getTable().'.company_id', $user->company_id);
        }
    }
}
