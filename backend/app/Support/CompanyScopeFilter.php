<?php

namespace App\Support;

use App\Models\Company;
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

    /**
     * TASK-256 / ADR-040 — "whose company is this answer about?"
     *
     * A platform-owned product has no single price and no single on-sale
     * state: it has one PER COMPANY (`company_product_settings`). So every
     * resolved field — effective price, may-this-company-sell-it, which
     * commission plan, which journey — needs a company to be resolved
     * against, and until now that was always `$request->user()->company_id`.
     *
     * That is right for a Company Admin and for an agent, and WRONG for the
     * only person who can edit these: a Super Admin belongs to no company, so
     * every shared product would report the central price and "not for sale"
     * no matter which company they had picked in the header. The header
     * picker already travels as `?company_id=` (it is the same value
     * apply() narrows the query with), so a Super Admin's context is simply
     * the company they are looking at — and "ทุกบริษัท" (no scope) honestly
     * has no context, which is what null means here.
     *
     * Same security contract as apply(): the parameter is read ONLY for a
     * Super Admin. For anyone else their own company is the answer and a
     * `?company_id=` in their query string is ignored, never trusted — this
     * resolves what to DISPLAY, and must not become a way to ask about a
     * company you cannot see.
     */
    public static function contextCompanyId(Request $request, string $column = 'company_id'): ?int
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (! $user->isSuperAdmin()) {
            return $user->company_id === null ? null : (int) $user->company_id;
        }

        return $request->filled($column) ? $request->integer($column) : null;
    }

    /**
     * The same company as an entity, for the callers that need more than its
     * id (Product::effectivePlanType() reads the company's plan type).
     *
     * Memoised on the request so a paginated page of products costs one
     * lookup, not one per row; for a non-Super-Admin it is the already-loaded
     * relation and costs no query at all.
     */
    public static function contextCompany(Request $request): ?Company
    {
        if ($request->attributes->has('scope_company')) {
            return $request->attributes->get('scope_company');
        }

        $user = $request->user();

        $company = $user !== null && ! $user->isSuperAdmin()
            ? $user->company
            : (($id = self::contextCompanyId($request)) === null ? null : Company::find($id));

        $request->attributes->set('scope_company', $company);

        return $company;
    }
}
