<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * TASK-209 / ADR-038 — the Super Admin's "which company am I working in"
 * scope, applied server-side.
 *
 * Why this exists at all: `TenantScope` deliberately does NOT narrow a Super
 * Admin (they are the cross-company operator), so every index endpoint hands
 * them every company's rows. The Admin app used to narrow that in the
 * browser with `.filter()` — which is wrong twice over:
 *
 *   1. It is a lie on any paginated endpoint. `GET /brands` used to
 *      `paginate()` at the default 15 while the UI rendered `data` with no
 *      pager, so brand #16 onward simply did not exist on screen (TASK-202).
 *      Client-side narrowing of page 1 cannot fix that.
 *   2. It ships other tenants' rows to the browser to then hide them. For
 *      `clients` that is PDPA-relevant personal health data.
 *
 * Security contract (BR-6, Section 5): this NARROWS, it can never widen.
 * The filter is applied only for a Super Admin — for anyone else TenantScope
 * has already pinned the query to their own company, and a `?company_id=` in
 * their query string is ignored entirely rather than trusted.
 */
class CompanyScopeFilter
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  bool  $includePlatformWide  Tables where a NULL company_id is a
     *                                     real business value meaning "applies
     *                                     to every company" (announcements,
     *                                     reward_items, gamification_rules —
     *                                     see TASK-209 §5). Those rows stay
     *                                     visible alongside the scoped
     *                                     company's own, because hiding them
     *                                     would misrepresent what an agent in
     *                                     that company actually sees.
     */
    public static function apply(
        Builder $query,
        Request $request,
        bool $includePlatformWide = false,
        string $column = 'company_id',
    ): void {
        $user = $request->user();

        if (! $user?->isSuperAdmin()) {
            return;
        }

        if (! $request->filled($column)) {
            // No scope requested = "ทุกบริษัท", the read-across view.
            return;
        }

        $companyId = $request->integer($column);

        $query->where(fn (Builder $q) => $includePlatformWide
            ? $q->where($column, $companyId)->orWhereNull($column)
            : $q->where($column, $companyId));
    }
}
