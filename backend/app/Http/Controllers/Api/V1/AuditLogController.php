<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// TASK-041 (4.1) — Policy & Report IA item 4, Audit Log Viewer. Section 6
// ("record every action that affects money, commission, status,
// certification, or permissions"). AuditLog is NOT TenantScope'd (see
// its own docblock) so — unlike every other index() in this codebase —
// the company_id narrowing for Company Admin is done explicitly here,
// by hand, rather than relying on a global scope (Section 5 rule 2 is
// about business tables; this is deliberately the one exception, called
// out in AuditLog's own docblock as "a Policy/Service concern").
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $user = $request->user();

        $query = AuditLog::query()->with('actor');

        if ($user->isSuperAdmin()) {
            // Super Admin sees across every company by default; ?company_id=
            // narrows to one, same optional-filter shape as ConfigHealthReportController.
            if ($request->filled('company_id')) {
                $query->where('company_id', $request->integer('company_id'));
            }
        } else {
            // Company Admin — hard-scoped to their own company (BR-6). Never
            // trust a client-supplied company_id for this role.
            $query->where('company_id', $user->company_id);
        }

        /*
         * TASK-240 — "WHICH USER DID WHAT", the question this table could
         * always answer and nothing ever asked.
         *
         * `actor_user_id` has been written on every row since the table was
         * created; no endpoint and no screen ever read it back, so the trail
         * could be browsed by action and by date but never by person.
         *
         * BR-6: a Company Admin may only ask about people in their own
         * company. The narrowing is done by intersecting with the company
         * scope already applied above rather than by rejecting the id —
         * an actor from another company simply matches nothing. A 403 here
         * would answer a question nobody should be able to ask: whether
         * that user id exists at all.
         */
        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->integer('actor_user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->input('action').'%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $logs = $query->orderByDesc('created_at')->paginate();

        return AuditLogResource::collection($logs);
    }
}
