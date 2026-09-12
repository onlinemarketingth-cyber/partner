<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Services\Commission\CommissionReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2026-09-11 (owner): "หากยังไม่ได้มีการ setup ค่าคอม ให้แจ้งเตือนในทุกหน้า".
 *
 * The one small verdict the admin console puts above every page. Small is a
 * requirement, not a style: this is fetched by every authenticated admin
 * session, so the response is a flat object of six fields with no collection
 * in it — see CommissionReadinessService for what each one means and why the
 * line between red and amber is drawn where it is.
 *
 * Company Admin (own company only, BR-6 — a client-supplied company_id is
 * never trusted for that role) or Super Admin (all companies, optionally
 * narrowed via ?company_id=). Agents get 403: an agent cannot fix a
 * commission rate, and "the system is not paying commission" in front of the
 * sales team is a morale problem rather than a help (owner decision — the
 * agent portal deliberately does not call this endpoint at all).
 *
 * Voucher staff never reach it either, and not by this gate: the whole
 * authenticated group sits behind `restrict.voucher-staff`, whose allowlist
 * does not include this path.
 */
class CommissionReadinessController extends Controller
{
    public function show(Request $request, CommissionReadinessService $service): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->can(Ability::CommissionReadinessView), 403);

        // Same shape as ConfigHealthReportController: only a Super Admin's
        // company_id is read at all, so the narrowing parameter can never
        // widen anybody's scope — for every other role the Service resolves
        // the company from the actor.
        $companyId = $user->isSuperAdmin() && $request->filled('company_id')
            ? $request->integer('company_id')
            : null;

        return response()->json($service->forActor($user, $companyId));
    }
}
