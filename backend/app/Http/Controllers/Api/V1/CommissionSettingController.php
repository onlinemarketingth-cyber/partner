<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommissionBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\UpdateCommissionSettingRequest;
use App\Http\Resources\CommissionSettingResource;
use App\Services\Commission\CommissionSettingService;
use Illuminate\Http\Request;

/**
 * 2026-09-12 — "how is commission calculated for this company": the basis
 * (sale price or PV) and the plan type.
 *
 * Same shape as CommissionSplitSettingController and the other per-company
 * commission settings. It exists so the admin commission screen stops reading
 * these two fields off the platform companies resource — see
 * CommissionSettingService's docblock for the failure that invited.
 *
 * show() is readable by any authenticated role scoped to their own company.
 * That is deliberately wider than the write and narrower than it looks: the
 * payload is two enums about the caller's own tenant, and an agent asking "how
 * am I paid" is the most reasonable question on this system. The Agent Portal
 * does not use it today; the read is not restricted to Admin purely so that
 * adding an agent-facing "how your commission works" panel later does not
 * require re-litigating a permission.
 *
 * An Agent/Company Admin is always forced to their own company_id; the
 * ?company_id= override is Super-Admin-only (BR-6).
 */
class CommissionSettingController extends Controller
{
    public function show(Request $request, CommissionSettingService $service): CommissionSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? ($request->integer('company_id') ?: null)
            : $request->user()->company_id;

        return new CommissionSettingResource($service->forCompany($companyId));
    }

    public function update(UpdateCommissionSettingRequest $request, CommissionSettingService $service): CommissionSettingResource
    {
        /*
         * The server decides which company is written, never the client —
         * the validated company_id is read only on the Super Admin branch,
         * exactly as every sibling settings controller does it. A Company
         * Admin cannot pass authorize() today, so the else-branch is a
         * structural guarantee rather than a live path; it is written out
         * because "unreachable" is a property that stops being true quietly.
         */
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        return new CommissionSettingResource($service->updateBasis(
            $companyId,
            CommissionBasis::from($request->validated('commission_basis')),
            $request->user(),
        ));
    }
}
